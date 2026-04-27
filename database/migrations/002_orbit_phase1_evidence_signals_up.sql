-- ============================================================================
-- ORBIT Phase 1 — Migration 002: Evidence signal columns
-- ============================================================================
-- Adds 5 columns to meridian_brand_content_items to support classification
-- of citation tier (T1/T2/T3) and evidence-strength signals.
--
-- All columns are nullable: pre-existing rows from Step 2 (crawl) won't be
-- broken; they'll just have NULL values until the classifier runs across them.
--
-- Forward-only migration — no data backfill required.
-- ============================================================================

BEGIN;

-- 1. Citation tier estimate (T1/T2/T3/unknown)
--    AIVO framework: T1 = training data (Wikipedia, GitHub, encyclopedic),
--                    T2 = industry authority (journals, regulatory bodies),
--                    T3 = immediacy layer (Reddit, Medium, news, blogs),
--                    unknown = doesn't naturally fit (marketing/landing pages).
ALTER TABLE meridian_brand_content_items
    ADD COLUMN citation_tier_estimate TEXT
        CHECK (citation_tier_estimate IN ('T1', 'T2', 'T3', 'unknown'));

-- 2. Evidence-strength signals (boolean flags)
ALTER TABLE meridian_brand_content_items
    ADD COLUMN has_data BOOLEAN;

ALTER TABLE meridian_brand_content_items
    ADD COLUMN has_external_citations BOOLEAN;

ALTER TABLE meridian_brand_content_items
    ADD COLUMN has_methodology BOOLEAN;

-- 3. Word count of content_text — useful for distinguishing depth
--    (e.g. 300-word marketing piece vs 4,000-word research write-up).
ALTER TABLE meridian_brand_content_items
    ADD COLUMN word_count INTEGER;

-- 4. Indexes — Phase 2 (matcher) will filter by tier and signals frequently.
CREATE INDEX idx_orbit_items_citation_tier
    ON meridian_brand_content_items (citation_tier_estimate)
    WHERE citation_tier_estimate IS NOT NULL;

CREATE INDEX idx_orbit_items_classified_at
    ON meridian_brand_content_items (brand_id, classified_at)
    WHERE classified_at IS NOT NULL;

COMMIT;
