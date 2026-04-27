-- ============================================================================
-- ORBIT Phase 1 — Migration 002: Evidence signal columns — ROLLBACK
-- ============================================================================
-- Reverses 002_orbit_phase1_evidence_signals_up.sql.
-- Drops the 5 columns and 2 indexes added in the up migration.
-- Any classifier output stored in those columns will be lost.
-- ============================================================================

BEGIN;

DROP INDEX IF EXISTS idx_orbit_items_citation_tier;
DROP INDEX IF EXISTS idx_orbit_items_classified_at;

ALTER TABLE meridian_brand_content_items DROP COLUMN IF EXISTS word_count;
ALTER TABLE meridian_brand_content_items DROP COLUMN IF EXISTS has_methodology;
ALTER TABLE meridian_brand_content_items DROP COLUMN IF EXISTS has_external_citations;
ALTER TABLE meridian_brand_content_items DROP COLUMN IF EXISTS has_data;
ALTER TABLE meridian_brand_content_items DROP COLUMN IF EXISTS citation_tier_estimate;

COMMIT;
