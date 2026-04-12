<?php
/**
 * AIVO Meridian — Schema Patch v1.3
 * Adds missing columns to 4 tables that were created with empty schemas.
 * Safe to run multiple times — uses ADD COLUMN IF NOT EXISTS.
 *
 * Usage: Hit this endpoint once, then delete the file.
 */

header('Content-Type: application/json');

$secret   = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
$expected = getenv('MERIDIAN_INTERNAL_SECRET') ?: '';
if (!$secret || $secret !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    http_response_code(500);
    echo json_encode(['error' => 'DATABASE_URL not set']);
    exit;
}

try {
    $parts  = parse_url($dbUrl);
    $host   = $parts['host'];
    $port   = $parts['port'] ?? 5432;
    $dbname = ltrim($parts['path'], '/');
    $user   = $parts['user'];
    $pass   = $parts['pass'];
    $dsn    = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
    $pdo    = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$patches = [];

// ── meridian_admin_sessions ──────────────────────────────────────────────────
$patches['meridian_admin_sessions'] = [
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS session_token  VARCHAR(128) NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS admin_email    VARCHAR(255) NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS ip_address     VARCHAR(45)",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS user_agent     TEXT",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS created_at     TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS last_active_at TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS expires_at     TIMESTAMP NOT NULL DEFAULT NOW() + INTERVAL '24 hours'",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS revoked        BOOLEAN DEFAULT FALSE",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS revoked_at     TIMESTAMP",
    "ALTER TABLE meridian_admin_sessions ADD COLUMN IF NOT EXISTS revoked_reason VARCHAR(255)",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_sessions_token_u ON meridian_admin_sessions(session_token)",
    "CREATE INDEX IF NOT EXISTS idx_admin_sessions_email   ON meridian_admin_sessions(admin_email)",
    "CREATE INDEX IF NOT EXISTS idx_admin_sessions_expires ON meridian_admin_sessions(expires_at)",
];

// ── meridian_research_findings ───────────────────────────────────────────────
$patches['meridian_research_findings'] = [
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS finding_ref         VARCHAR(64) NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS created_at          TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS updated_at          TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS published_at        TIMESTAMP",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS status              VARCHAR(20) DEFAULT 'draft'",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS finding_type        VARCHAR(50)",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS platform            VARCHAR(50)",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS category            VARCHAR(100)",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS title               VARCHAR(255) NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS summary             TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS detail              TEXT",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS evidence_count      INT DEFAULT 0",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS confidence_level    VARCHAR(20)",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS corpus_from         DATE",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS corpus_to           DATE",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS methodology_version VARCHAR(20)",
    "ALTER TABLE meridian_research_findings ADD COLUMN IF NOT EXISTS tags                JSONB",
    "CREATE INDEX IF NOT EXISTS idx_findings_status   ON meridian_research_findings(status)",
    "CREATE INDEX IF NOT EXISTS idx_findings_type     ON meridian_research_findings(finding_type)",
    "CREATE INDEX IF NOT EXISTS idx_findings_platform ON meridian_research_findings(platform)",
];

// ── meridian_competitive_citation_gaps ───────────────────────────────────────
$patches['meridian_competitive_citation_gaps'] = [
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS created_at             TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS updated_at             TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS category               VARCHAR(100) NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS subcategory            VARCHAR(100)",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS platform               VARCHAR(50)  NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS probe_mode             VARCHAR(50)  NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS displacing_brand_tier  VARCHAR(100)",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS citation_source_type   VARCHAR(100)",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS citation_tier          VARCHAR(10)",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS displacement_frequency DECIMAL(5,4)",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS evidence_count         INT DEFAULT 0",
    "ALTER TABLE meridian_competitive_citation_gaps ADD COLUMN IF NOT EXISTS methodology_version    VARCHAR(20)",
    "CREATE INDEX IF NOT EXISTS idx_citation_gaps_category ON meridian_competitive_citation_gaps(category)",
    "CREATE INDEX IF NOT EXISTS idx_citation_gaps_platform ON meridian_competitive_citation_gaps(platform)",
];

// ── meridian_platform_remediation_plans ──────────────────────────────────────
$patches['meridian_platform_remediation_plans'] = [
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS audit_id                   INT NOT NULL DEFAULT 0",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS brand_id                   INT NOT NULL DEFAULT 0",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS created_at                 TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS updated_at                 TIMESTAMP DEFAULT NOW()",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS platform                   VARCHAR(50) NOT NULL DEFAULT ''",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS rcs_score                  INT",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS dit_turn                   INT",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS displacement_type          VARCHAR(100)",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS handoff_captured           BOOLEAN",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS actions                    JSONB",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS projected_dit_improvement  INT",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS projected_rcs_uplift       INT",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS projected_weeks            INT",
    "ALTER TABLE meridian_platform_remediation_plans ADD COLUMN IF NOT EXISTS status                     VARCHAR(20) DEFAULT 'active'",
    "CREATE INDEX IF NOT EXISTS idx_plat_remediation_audit    ON meridian_platform_remediation_plans(audit_id)",
    "CREATE INDEX IF NOT EXISTS idx_plat_remediation_brand    ON meridian_platform_remediation_plans(brand_id)",
    "CREATE INDEX IF NOT EXISTS idx_plat_remediation_platform ON meridian_platform_remediation_plans(platform)",
];

// ── RUN ALL PATCHES ───────────────────────────────────────────────────────────
$results = [];
$allOk   = true;

foreach ($patches as $table => $statements) {
    $results[$table] = [];
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
            $results[$table][] = 'ok: ' . substr($sql, 0, 60);
        } catch (Exception $e) {
            $results[$table][] = 'error: ' . $e->getMessage();
            $allOk = false;
        }
    }
}

// ── VERIFY COLUMN COUNTS ──────────────────────────────────────────────────────
$verify = [];
$tables = ['meridian_admin_sessions', 'meridian_research_findings',
           'meridian_competitive_citation_gaps', 'meridian_platform_remediation_plans'];

foreach ($tables as $t) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as col_count
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = ?
    ");
    $stmt->execute([$t]);
    $row = $stmt->fetch();
    $verify[$t] = (int)$row['col_count'] . ' columns';
}

http_response_code($allOk ? 200 : 207);
echo json_encode([
    'patch'    => 'v1.3',
    'status'   => $allOk ? 'success' : 'partial',
    'results'  => $results,
    'verify'   => $verify,
    'message'  => $allOk
        ? 'All columns patched. Delete this file now.'
        : 'Some statements had issues — check results above.'
], JSON_PRETTY_PRINT);
