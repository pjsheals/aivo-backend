-- ============================================================
-- ORBIT Phase 1 — Brand Content Index — ROLLBACK
-- 001_orbit_phase1_schema_down.sql
--
-- Drops the three ORBIT tables in dependency order.
-- Does NOT drop the pgvector extension — other future tables may use it.
-- ============================================================

DROP TABLE IF EXISTS meridian_brand_content_classifications_log;
DROP TABLE IF EXISTS meridian_brand_content_items;
DROP TABLE IF EXISTS meridian_brand_content_sources;
