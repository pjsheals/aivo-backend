<?php
/**
 * AIVO Meridian — Schema Migration v1.2
 * Adds 6 new tables for admin system and research corpus.
 * ADDITIVE ONLY — zero changes to existing 52 tables.
 *
 * Usage: Hit this endpoint once, then delete the file.
 * Access: Requires MERIDIAN_INTERNAL_SECRET header for security.
 */

header('Content-Type: application/json');

// Security check
$secret = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
$expected = getenv('MERIDIAN_INTERNAL_SECRET') ?: '';
if (!$secret || $secret !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// DB connection — parse Railway's DATABASE_URL into PDO DSN
$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    http_response_code(500);
    echo json_encode(['error' => 'DATABASE_URL not set']);
    exit;
}

try {
    $parts = parse_url($dbUrl);
    $host   = $parts['host'];
    $port   = $parts['port'] ?? 5432;
    $dbname = ltrim($parts['path'], '/');
    $user   = $parts['user'];
    $pass   = $parts['pass'];

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$migrations = [];

// ─────────────────────────────────────────────────────────────────────────────
// TABLE 1: meridian_admin_sessions
// Superadmin session management, separate from agency user sessions.
// ─────────────────────────────────────────────────────────────────────────────
$migrations['meridian_admin_sessions'] = "
CREATE TABLE IF NOT EXISTS meridian_admin_sessions (
    id                  SERIAL PRIMARY KEY,
    session_token       VARCHAR(128) NOT NULL UNIQUE,
    admin_email         VARCHAR(255) NOT NULL,
    ip_address          VARCHAR(45),
    user_agent          TEXT,
    created_at          TIMESTAMP DEFAULT NOW(),
    last_active_at      TIMESTAMP DEFAULT NOW(),
    expires_at          TIMESTAMP NOT NULL,
    revoked             BOOLEAN DEFAULT FALSE,
    revoked_at          TIMESTAMP,
    revoked_reason      VARCHAR(255)
);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_token ON meridian_admin_sessions(session_token);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_email ON meridian_admin_sessions(admin_email);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_expires ON meridian_admin_sessions(expires_at);
";

// ─────────────────────────────────────────────────────────────────────────────
// TABLE 2: meridian_corpus_contributions
// Records anonymised probe run signals contributed to the LLM behaviour corpus.
// No brand names, agency IDs, or client-identifiable data stored here.
// ─────────────────────────────────────────────────────────────────────────────
$migrations['meridian_corpus_contributions'] = "
CREATE TABLE IF NOT EXISTS meridian_corpus_contributions (
    id                      SERIAL PRIMARY KEY,
    contribution_ref        VARCHAR(64) NOT NULL UNIQUE,  -- anonymous hash ref, not probe_run_id
    contributed_at          TIMESTAMP DEFAULT NOW(),

    -- Category context (anonymised)
    category                VARCHAR(100),
    subcategory             VARCHAR(100),
    price_tier              VARCHAR(50),   -- luxury / prestige / masstige / accessible

    -- Probe context
    platform                VARCHAR(50),   -- chatgpt / gemini / perplexity / claude
    probe_mode              VARCHAR(50),   -- anchored / generic / undirected
    total_turns             INT,

    -- DIT signal
    dit_turn                INT,           -- NULL = no DIT observed
    dit_type                VARCHAR(50),   -- comparative / evaluative / educational / null_dit

    -- Displacement signal
    displacement_tier       VARCHAR(100),  -- e.g. clinical_luxury / channel_first / lifestyle
    handoff_captured        BOOLEAN,
    termination_type        VARCHAR(50),   -- exhausted / handoff / loop / absent

    -- Taxonomy sequence (JSON array of turn-level types)
    taxonomy_sequence       JSONB,

    -- RCS band (not score — band only to preserve anonymity)
    rcs_band                VARCHAR(20),   -- strong / at_risk / eliminated

    -- Methodology version used
    methodology_version     VARCHAR(20)    -- e.g. 1.0, 1.1, 1.2
);
CREATE INDEX IF NOT EXISTS idx_corpus_category ON meridian_corpus_contributions(category);
CREATE INDEX IF NOT EXISTS idx_corpus_platform ON meridian_corpus_contributions(platform);
CREATE INDEX IF NOT EXISTS idx_corpus_probe_mode ON meridian_corpus_contributions(probe_mode);
CREATE INDEX IF NOT EXISTS idx_corpus_dit_turn ON meridian_corpus_contributions(dit_turn);
CREATE INDEX IF NOT EXISTS idx_corpus_contributed_at ON meridian_corpus_contributions(contributed_at);
";

// ─────────────────────────────────────────────────────────────────────────────
// TABLE 3: meridian_research_findings
// Structured research insights derived from corpus analysis.
// Human-curated layer on top of raw corpus signals.
// ─────────────────────────────────────────────────────────────────────────────
$migrations['meridian_research_findings'] = "
CREATE TABLE IF NOT EXISTS meridian_research_findings (
    id                  SERIAL PRIMARY KEY,
    finding_ref         VARCHAR(64) NOT NULL UNIQUE,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW(),
    published_at        TIMESTAMP,
    status              VARCHAR(20) DEFAULT 'draft',  -- draft / published / archived

    -- Classification
    finding_type        VARCHAR(50),   -- platform_behaviour / category_pattern / intervention_effect / temporal_shift
    platform            VARCHAR(50),   -- specific platform or 'cross_platform'
    category            VARCHAR(100),  -- specific category or 'cross_category'

    -- Finding content
    title               VARCHAR(255) NOT NULL,
    summary             TEXT NOT NULL,
    detail              TEXT,
    evidence_count      INT DEFAULT 0,    -- number of corpus contributions supporting this finding
    confidence_level    VARCHAR(20),      -- low / medium / high

    -- Corpus date range this finding covers
    corpus_from         DATE,
    corpus_to           DATE,

    -- Methodology version
    methodology_version VARCHAR(20),

    -- Tags for research paper indexing
    tags                JSONB
);
CREATE INDEX IF NOT EXISTS idx_findings_status ON meridian_research_findings(status);
CREATE INDEX IF NOT EXISTS idx_findings_type ON meridian_research_findings(finding_type);
CREATE INDEX IF NOT EXISTS idx_findings_platform ON meridian_research_findings(platform);
";

// ─────────────────────────────────────────────────────────────────────────────
// TABLE 4: meridian_competitive_citation_gaps
// Specific competitor brands and source types driving displacement at T4,
// by category. Used to surface remediation context in brand results.
// ─────────────────────────────────────────────────────────────────────────────
$migrations['meridian_competitive_citation_gaps'] = "
CREATE TABLE IF NOT EXISTS meridian_competitive_citation_gaps (
    id                      SERIAL PRIMARY KEY,
    created_at              TIMESTAMP DEFAULT NOW(),
    updated_at              TIMESTAMP DEFAULT NOW(),

    -- Context
    category                VARCHAR(100) NOT NULL,
    subcategory             VARCHAR(100),
    platform                VARCHAR(50) NOT NULL,
    probe_mode              VARCHAR(50) NOT NULL,

    -- Displacement driver
    displacing_brand_tier   VARCHAR(100),   -- e.g. clinical_luxury, channel_first (no brand names)
    citation_source_type    VARCHAR(100),   -- e.g. dermatology_journal, reddit_community, brand_site
    citation_tier           VARCHAR(10),    -- T1 / T2 / T3
    displacement_frequency  DECIMAL(5,4),   -- 0.0–1.0, proportion of corpus probes showing this pattern

    -- Supporting signal count
    evidence_count          INT DEFAULT 0,
    methodology_version     VARCHAR(20)
);
CREATE INDEX IF NOT EXISTS idx_citation_gaps_category ON meridian_competitive_citation_gaps(category);
CREATE INDEX IF NOT EXISTS idx_citation_gaps_platform ON meridian_competitive_citation_gaps(platform);
";

// ─────────────────────────────────────────────────────────────────────────────
// TABLE 5: meridian_intervention_benchmarks
// Expected DIT movement by intervention type, citation tier, category, platform.
// Populated from corpus data. Powers the remediation timeline estimates.
// ─────────────────────────────────────────────────────────────────────────────
$migrations['meridian_intervention_benchmarks'] = "
CREATE TABLE IF NOT EXISTS meridian_intervention_benchmarks (
    id                          SERIAL PRIMARY KEY,
    created_at                  TIMESTAMP DEFAULT NOW(),
    updated_at                  TIMESTAMP DEFAULT NOW(),

    -- Scope
    category                    VARCHAR(100),   -- NULL = cross-category benchmark
    platform                    VARCHAR(50),    -- NULL = cross-platform benchmark
    citation_tier               VARCHAR(10) NOT NULL,   -- T1 / T2 / T3

    -- Intervention
    intervention_type           VARCHAR(100) NOT NULL,  -- e.g. wikipedia_coverage, dermatology_publication, reddit_seeding
    intervention_description    TEXT,

    -- Expected outcomes
    avg_dit_movement_turns      DECIMAL(4,2),   -- average DIT improvement in turns
    avg_weeks_to_effect         INT,            -- weeks before measurable DIT change
    effect_durability_weeks     INT,            -- how long the effect lasts

    -- Confidence
    evidence_count              INT DEFAULT 0,
    confidence_level            VARCHAR(20),    -- low / medium / high
    methodology_version         VARCHAR(20)
);
CREATE INDEX IF NOT EXISTS idx_benchmarks_category ON meridian_intervention_benchmarks(category);
CREATE INDEX IF NOT EXISTS idx_benchmarks_tier ON meridian_intervention_benchmarks(citation_tier);
CREATE INDEX IF NOT EXISTS idx_benchmarks_platform ON meridian_intervention_benchmarks(platform);
";

// ─────────────────────────────────────────────────────────────────────────────
// TABLE 6: meridian_platform_remediation_plans
// Per-platform remediation split, separate from the unified plan.
// Allows the frontend to show platform-specific action cards.
// ─────────────────────────────────────────────────────────────────────────────
$migrations['meridian_platform_remediation_plans'] = "
CREATE TABLE IF NOT EXISTS meridian_platform_remediation_plans (
    id                  SERIAL PRIMARY KEY,
    audit_id            INT NOT NULL,   -- references meridian_audits(id)
    brand_id            INT NOT NULL,   -- references meridian_brands(id)
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW(),

    -- Platform
    platform            VARCHAR(50) NOT NULL,   -- chatgpt / gemini / perplexity / claude

    -- Current state for this platform
    rcs_score           INT,
    dit_turn            INT,
    displacement_type   VARCHAR(100),
    handoff_captured    BOOLEAN,

    -- Priority actions for this platform (JSON array)
    actions             JSONB,  -- [{priority, citation_tier, action, target_sources, expected_weeks}]

    -- Expected outcome
    projected_dit_improvement   INT,    -- turns
    projected_rcs_uplift        INT,    -- points
    projected_weeks             INT,

    status              VARCHAR(20) DEFAULT 'active'  -- active / superseded / archived
);
CREATE INDEX IF NOT EXISTS idx_plat_remediation_audit ON meridian_platform_remediation_plans(audit_id);
CREATE INDEX IF NOT EXISTS idx_plat_remediation_brand ON meridian_platform_remediation_plans(brand_id);
CREATE INDEX IF NOT EXISTS idx_plat_remediation_platform ON meridian_platform_remediation_plans(platform);
";

// ─────────────────────────────────────────────────────────────────────────────
// RUN ALL MIGRATIONS
// ─────────────────────────────────────────────────────────────────────────────
$results = [];
$allOk = true;

foreach ($migrations as $tableName => $sql) {
    try {
        $pdo->exec($sql);
        $results[$tableName] = 'created_or_exists';
    } catch (Exception $e) {
        $results[$tableName] = 'error: ' . $e->getMessage();
        $allOk = false;
    }
}

// Verify all 6 tables exist
$check = $pdo->query("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND table_name IN (
        'meridian_admin_sessions',
        'meridian_corpus_contributions',
        'meridian_research_findings',
        'meridian_competitive_citation_gaps',
        'meridian_intervention_benchmarks',
        'meridian_platform_remediation_plans'
    )
    ORDER BY table_name
")->fetchAll(PDO::FETCH_COLUMN);

http_response_code($allOk ? 200 : 500);
echo json_encode([
    'migration'     => 'v1.2',
    'status'        => $allOk ? 'success' : 'partial_failure',
    'tables_run'    => $results,
    'tables_verified' => $check,
    'total_verified'  => count($check),
    'expected'        => 6,
    'message'       => $allOk
        ? 'All 6 tables created. Delete this file now.'
        : 'One or more tables failed. Check tables_run for details.'
], JSON_PRETTY_PRINT);
