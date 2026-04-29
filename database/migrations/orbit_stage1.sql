-- =============================================================================
-- ORBIT Stage 1 — Schema migration
-- =============================================================================
-- Creates the four foundational structures for the gap-triggered evidence
-- discovery layer:
--   1. pgvector extension (for embedding-based relevance scoring)
--   2. citation_platforms     — the v2 taxonomy + search routing config
--   3. orbit_search_runs      — audit trail of every gap-triggered search
--   4. orbit_search_results   — every candidate considered (accepted or not)
--
-- Run inside psql connected to the Railway database.
-- All ID columns are bigint to match meridian_* convention.
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- 1. pgvector extension
-- -----------------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS vector;


-- -----------------------------------------------------------------------------
-- 2. citation_platforms
-- -----------------------------------------------------------------------------
-- DUAL ROLE:
--   (a) Classification: when a URL arrives, look up its tier/score
--   (b) Search routing: when a gap is identified, decide which platforms to query
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citation_platforms (
    id                    BIGSERIAL PRIMARY KEY,

    -- Identification
    platform_name         VARCHAR(255) NOT NULL,                 -- 'Wikipedia', 'PubMed', 'Reddit'
    domain                VARCHAR(255),                          -- 'wikipedia.org' (NULL if pattern-only)
    pattern               VARCHAR(500),                          -- regex / glob for URL classification (NULL if domain-only)

    -- Taxonomy v2
    tier                  VARCHAR(10)  NOT NULL,                 -- 'T1.1', 'T1.2', 'T2.5', 'T3.1', 'S.4', etc.
    score_base            INTEGER      NOT NULL,                 -- 10-100 (T1: 70-100, T2: 40-70, T3: 10-40)
    sector                TEXT[]       NOT NULL DEFAULT '{}',    -- e.g. ARRAY['beauty','cpg']; empty = applies to all sectors
    tags                  TEXT[]       NOT NULL DEFAULT '{}',    -- 'brand-owned','newsletter','counter-evidence', etc.

    -- Search routing
    searchable            BOOLEAN      NOT NULL DEFAULT FALSE,
    search_method         VARCHAR(20),                           -- 'api' | 'site_search' | 'google_site' | 'sitemap_crawl'
    search_endpoint       VARCHAR(500),                          -- API base URL or site root
    api_auth_type         VARCHAR(20),                           -- 'none' | 'api_key' | 'header' | 'oauth' | 'bearer'
    rate_limit_qpm        INTEGER,                               -- queries per minute (NULL = unknown/unbounded)
    cost_per_query        NUMERIC(10, 6),                        -- USD per query; NULL = N/A (not searchable), 0 = free, else cost

    -- Behavioural metadata
    typical_recency       VARCHAR(20),                           -- 'live'|'days'|'weeks'|'months'|'years'|'static'
    sentiment_relevance   BOOLEAN      NOT NULL DEFAULT TRUE,    -- whether sentiment classification matters for this source

    -- Lifecycle
    deprecated_at         TIMESTAMPTZ,                           -- NULL = active
    notes                 TEXT,
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT citation_platforms_score_range
        CHECK (score_base >= 0 AND score_base <= 100),
    CONSTRAINT citation_platforms_search_method_valid
        CHECK (search_method IS NULL OR search_method IN ('api','site_search','google_site','sitemap_crawl')),
    CONSTRAINT citation_platforms_auth_valid
        CHECK (api_auth_type IS NULL OR api_auth_type IN ('none','api_key','header','oauth','bearer')),
    CONSTRAINT citation_platforms_recency_valid
        CHECK (typical_recency IS NULL OR typical_recency IN ('live','days','weeks','months','years','static')),
    CONSTRAINT citation_platforms_id_required
        CHECK (domain IS NOT NULL OR pattern IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_citation_platforms_domain
    ON citation_platforms (domain);
CREATE INDEX IF NOT EXISTS idx_citation_platforms_tier
    ON citation_platforms (tier);
CREATE INDEX IF NOT EXISTS idx_citation_platforms_searchable
    ON citation_platforms (searchable, search_method)
    WHERE searchable = TRUE AND deprecated_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_citation_platforms_sector_gin
    ON citation_platforms USING GIN (sector);
CREATE INDEX IF NOT EXISTS idx_citation_platforms_tags_gin
    ON citation_platforms USING GIN (tags);

COMMENT ON TABLE citation_platforms IS
    'Citation tier taxonomy v2. Dual role: URL classification + ORBIT search routing. Editable via super-admin Meridian UI.';


-- -----------------------------------------------------------------------------
-- 3. orbit_search_runs
-- -----------------------------------------------------------------------------
-- Every time ORBIT runs a search against a gap, a row lands here. This is the
-- audit trail and the parent for orbit_search_results.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orbit_search_runs (
    id                       BIGSERIAL PRIMARY KEY,

    -- Gap linkage (the trigger)
    gap_id                   BIGINT NOT NULL,
    brand_id                 BIGINT NOT NULL,                    -- denormalised from gap for fast tenant filter
    agency_id                BIGINT NOT NULL,                    -- denormalised for multi-tenant isolation

    -- Search intent
    claim_text               TEXT   NOT NULL,                    -- the statement we're seeking evidence for
    claim_embedding          vector(1536),                       -- text-embedding-3-small; populated by embedding service
    requested_tiers          TEXT[] NOT NULL DEFAULT '{}',       -- e.g. ARRAY['T1.2','T1.3','T2.4']
    requested_sentiment      VARCHAR(20) NOT NULL DEFAULT 'positive',  -- 'positive'|'neutral'|'any'

    -- Execution
    platforms_searched       TEXT[] NOT NULL DEFAULT '{}',       -- citation_platforms.platform_name list actually queried
    platforms_skipped        JSONB  NOT NULL DEFAULT '{}'::jsonb, -- { "platform_name": "skip_reason" }
    results_count            INTEGER NOT NULL DEFAULT 0,
    accepted_count           INTEGER NOT NULL DEFAULT 0,         -- how many candidates the user kept
    total_cost_usd           NUMERIC(10, 4) NOT NULL DEFAULT 0,
    latency_ms               INTEGER,

    -- Provenance
    triggered_by             VARCHAR(20) NOT NULL DEFAULT 'user', -- 'user'|'auto'|'scheduled'|'admin'
    triggered_by_user_id     BIGINT,                              -- null when auto/scheduled

    -- Status
    status                   VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending'|'searching'|'complete'|'error'|'cancelled'
    error_message            TEXT,

    -- Timestamps
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at             TIMESTAMPTZ,

    -- FKs
    CONSTRAINT orbit_search_runs_gap_id_fkey
        FOREIGN KEY (gap_id) REFERENCES meridian_competitive_citation_gaps(id) ON DELETE CASCADE,
    CONSTRAINT orbit_search_runs_brand_id_fkey
        FOREIGN KEY (brand_id) REFERENCES meridian_brands(id),
    CONSTRAINT orbit_search_runs_agency_id_fkey
        FOREIGN KEY (agency_id) REFERENCES meridian_agencies(id),

    -- Constraints
    CONSTRAINT orbit_search_runs_status_valid
        CHECK (status IN ('pending','searching','complete','error','cancelled')),
    CONSTRAINT orbit_search_runs_triggered_valid
        CHECK (triggered_by IN ('user','auto','scheduled','admin')),
    CONSTRAINT orbit_search_runs_sentiment_valid
        CHECK (requested_sentiment IN ('positive','neutral','any'))
);

CREATE INDEX IF NOT EXISTS idx_orbit_runs_gap
    ON orbit_search_runs (gap_id);
CREATE INDEX IF NOT EXISTS idx_orbit_runs_brand
    ON orbit_search_runs (brand_id);
CREATE INDEX IF NOT EXISTS idx_orbit_runs_agency
    ON orbit_search_runs (agency_id);
CREATE INDEX IF NOT EXISTS idx_orbit_runs_status_created
    ON orbit_search_runs (status, created_at DESC);

COMMENT ON TABLE orbit_search_runs IS
    'Audit trail of every ORBIT search. Parent of orbit_search_results. Cascade-deletes when its source gap is deleted.';


-- -----------------------------------------------------------------------------
-- 4. orbit_search_results
-- -----------------------------------------------------------------------------
-- Every candidate URL surfaced by an ORBIT run, accepted or not.
-- Scoring values are denormalised at write time so historical scores remain
-- reproducible even if citation_platforms.score_base is later edited.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orbit_search_results (
    id                       BIGSERIAL PRIMARY KEY,
    search_run_id            BIGINT NOT NULL,
    platform_id              BIGINT,                              -- NULL = uncategorised T3.9 fallback

    -- The candidate
    url                      TEXT   NOT NULL,
    title                    TEXT,
    snippet                  TEXT,
    author                   TEXT,
    published_at             TIMESTAMPTZ,

    -- Denormalised platform metadata (frozen at search time)
    source_platform          VARCHAR(255),                       -- e.g. 'Wikipedia' or domain
    tier                     VARCHAR(10) NOT NULL,                -- 'T1.1' etc.

    -- Scoring (all denormalised — historical scores stay reproducible)
    base_tier_score          NUMERIC(6, 2) NOT NULL,
    recency_multiplier       NUMERIC(4, 3) NOT NULL DEFAULT 1.000, -- 1.0 = <30d, decays to 0.5 over 5y
    relevance_multiplier     NUMERIC(4, 3) NOT NULL DEFAULT 1.000, -- cosine similarity 0.0-1.5
    sector_match_bonus       NUMERIC(4, 3) NOT NULL DEFAULT 0.000, -- +0.2 if platform.sector matches brand.sector
    sentiment_penalty        NUMERIC(6, 2) NOT NULL DEFAULT 0.00,  -- -30 if counter-evidence and request positive
    candidate_score          NUMERIC(8, 2) NOT NULL,               -- final computed score

    -- Embedding for relevance scoring
    candidate_embedding      vector(1536),

    -- Sentiment classification
    sentiment_hint           VARCHAR(20),                          -- 'positive'|'neutral'|'negative'|'counter'

    -- Raw API payload for debugging / re-scoring
    raw_response             JSONB,

    -- User selection
    accepted                 BOOLEAN NOT NULL DEFAULT FALSE,
    accepted_at              TIMESTAMPTZ,
    accepted_by_user_id      BIGINT,
    rejection_reason         VARCHAR(50),                          -- 'irrelevant'|'low_quality'|'duplicate'|'off_brand'|'other'

    -- Atom linkage (set when accepted candidate is promoted into atom pipeline)
    -- meridian_atoms.id is UUID (gen_random_uuid()), not bigint
    atom_id                  UUID,

    -- Timestamps
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- FKs
    CONSTRAINT orbit_search_results_run_fkey
        FOREIGN KEY (search_run_id) REFERENCES orbit_search_runs(id) ON DELETE CASCADE,
    CONSTRAINT orbit_search_results_platform_fkey
        FOREIGN KEY (platform_id) REFERENCES citation_platforms(id) ON DELETE SET NULL,
    CONSTRAINT orbit_search_results_atom_fkey
        FOREIGN KEY (atom_id) REFERENCES meridian_atoms(id) ON DELETE SET NULL,

    -- Constraints
    CONSTRAINT orbit_search_results_sentiment_valid
        CHECK (sentiment_hint IS NULL OR sentiment_hint IN ('positive','neutral','negative','counter'))
);

CREATE INDEX IF NOT EXISTS idx_orbit_results_run
    ON orbit_search_results (search_run_id);
CREATE INDEX IF NOT EXISTS idx_orbit_results_run_score
    ON orbit_search_results (search_run_id, candidate_score DESC);
CREATE INDEX IF NOT EXISTS idx_orbit_results_accepted
    ON orbit_search_results (search_run_id, accepted)
    WHERE accepted = TRUE;
CREATE INDEX IF NOT EXISTS idx_orbit_results_atom
    ON orbit_search_results (atom_id)
    WHERE atom_id IS NOT NULL;

-- Vector similarity index — defer until data exists.
-- Run this AFTER seed + first batch of searches:
--   CREATE INDEX idx_orbit_results_embedding_hnsw
--     ON orbit_search_results USING hnsw (candidate_embedding vector_cosine_ops);

COMMENT ON TABLE orbit_search_results IS
    'Every candidate surfaced by an ORBIT search. Scoring values denormalised at write time so historical scores are reproducible.';


-- -----------------------------------------------------------------------------
-- updated_at trigger for citation_platforms (mirrors meridian_brands convention)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION orbit_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_citation_platforms_updated_at ON citation_platforms;
CREATE TRIGGER trg_citation_platforms_updated_at
    BEFORE UPDATE ON citation_platforms
    FOR EACH ROW
    EXECUTE FUNCTION orbit_set_updated_at();

COMMIT;

-- =============================================================================
-- Verification queries (run after COMMIT)
-- =============================================================================
-- \d citation_platforms
-- \d orbit_search_runs
-- \d orbit_search_results
-- SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';
