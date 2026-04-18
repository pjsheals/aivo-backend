<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

class MeridianRemediationEngine
{
    private string $claudeApiKey;

    private const DISPLACEMENT_TAXONOMY = [
        'COMPARATIVE'    => 'Model steers toward direct comparison — medium risk, gateway mechanism',
        'EVALUATIVE'     => 'Model applies criteria verdict — high risk at optimisation turn',
        'CHANNEL'        => 'Model provides purchase pathway — high positive if primary, high negative if competitor',
        'EDUCATIONAL'    => 'Generic category content — low individual risk, critical in sequence (Gemini Drift Arc)',
        'PRICE'          => 'Cost-efficiency reframe — high risk for premium/luxury brands, non-recoverable',
        'DEFLECTING'     => 'Avoidance/redirection — medium risk individually, terminal in sequence',
        'CONCLUSION'     => 'Journey endpoint signal — positive if primary, negative if competitor or absent',
        'CONVERSION_LOOP'=> 'Four+ identical suggestion types — medium aggregate risk, no conversion',
        'FULFILMENT'     => 'Delivers request with no forward steering — neutral to negative',
        'SUBSTITUTION'   => 'Active dupe/alternative provision — completed displacement event, high risk',
        'EQUALISATION'   => 'Parity positioning with competitor — prevents unambiguous handoff capture',
        'SOLICITATION'   => 'Requests user data as prerequisite — terminal in undirected conditions',
        'CAPTURE'        => 'Full journey transfer to competitor — highest severity, simultaneous competitor gain',
        'FAILURE'        => 'Technical error termination — terminal, no handoff',
    ];

    private const PLATFORM_FINGERPRINTS = [
        'chatgpt'    => 'Channel-first default in undirected; clinical evidence filter in directed. Generic set by citation authority tier. Primary intervention: T1/T2 citation architecture.',
        'gemini'     => 'Educational Drift Arc — displaces every anchored brand at T2 without exception. Goldilocks framework excludes luxury and mass-market. Primary intervention: brand-specific content density.',
        'perplexity' => 'Brand advocacy without close — personalisation deflection loop prevents purchase. Runtime retrieval risk: live web content introduces unforeseeable competitors. Primary intervention: monthly T3 monitoring.',
        'grok'       => 'Zero-variance performance-first determinism. Primary criterion evaluation is wholly decisive. Primary intervention: establish unambiguous dominance in the specific performance criterion.',
    ];

    public function __construct()
    {
        $this->claudeApiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    }

    /**
     * @param int       $brandId
     * @param int       $agencyId
     * @param bool      $force    — bypass cached result
     * @param int|null  $auditId  — if supplied, scope to this specific audit;
     *                              otherwise falls back to most recent completed audit
     */
    public function generateRemediation(int $brandId, int $agencyId, bool $force = false, ?int $auditId = null): array
    {
        if (!$force) {
            $existing = $this->fetchExisting($brandId);
            if ($existing) return ['status' => 'cached', 'data' => $existing];
        }

        $auditData = $this->loadAuditData($brandId, $agencyId, $auditId);
        if (!$auditData) {
            return ['status' => 'error', 'message' => 'No completed audit found for this brand'];
        }

        $prompt = $this->buildPrompt($auditData);
        $result = $this->callClaude($prompt);

        if (!$result['success']) {
            return ['status' => 'error', 'message' => $result['error']];
        }

        $parsed = $this->parseJson($result['content']);
        if (!$parsed) {
            return ['status' => 'error', 'message' => 'Could not parse remediation output'];
        }

        $this->store($brandId, $auditData['audit_id'], $parsed);

        return ['status' => 'generated', 'data' => $parsed];
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    private function loadAuditData(int $brandId, int $agencyId, ?int $auditId = null): ?array
    {
        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();
        if (!$brand) return null;

        // Use specific audit_id if provided, otherwise fall back to most recent completed
        if ($auditId) {
            $audit = DB::table('meridian_audits')
                ->where('id', $auditId)
                ->where('brand_id', $brandId)
                ->where('status', 'completed')
                ->first();
        } else {
            $audit = DB::table('meridian_audits')
                ->where('brand_id', $brandId)
                ->where('status', 'completed')
                ->whereIn('audit_type', ['full', 'directed_bjp', 'undirected_bjp'])
                ->orderByDesc('completed_at')
                ->first();
        }

        if (!$audit) return null;

        $result = DB::table('meridian_brand_audit_results')
            ->where('audit_id', $audit->id)
            ->first();

        $probeRuns = DB::table('meridian_probe_runs')
            ->where('audit_id', $audit->id)
            ->where('status', 'completed')
            ->get();

        // ── Load PSOS from its own separate audit record ──────────────────────
        // PSOS is stored as a separate audit_type='psos' entry, not on the BJP
        // audit result row. Query it independently, same pattern as brand.detail().
        $psosResult = null;
        $psosAudit  = DB::table('meridian_audits')
            ->where('brand_id', $brandId)
            ->where('status', 'completed')
            ->where('audit_type', 'psos')
            ->orderByDesc('completed_at')
            ->first();
        if ($psosAudit) {
            $psosRow = DB::table('meridian_brand_audit_results')
                ->where('audit_id', $psosAudit->id)
                ->whereNotNull('psos_result')
                ->first(['psos_result']);
            if ($psosRow) {
                $psosResult = json_decode($psosRow->psos_result, true);
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        return [
            'audit_id'       => (int)$audit->id,
            'brand_id'       => $brandId,
            'brand_name'     => $brand->name,
            'brand_category' => $brand->category ?? 'Unknown',
            'rcs_total'      => $result ? (float)$result->rcs_total : 0,
            'ad_verdict'     => $result ? ($result->ad_verdict ?? 'No verdict') : 'No verdict',
            'revenue_at_risk'=> $result ? (float)($result->revenue_at_risk ?? 0) : 0,
            'journey_runs'   => $result ? json_decode($result->journey_runs ?? '[]', true) : [],
            'citation_brief' => $result ? json_decode($result->citation_brief ?? 'null', true) : null,
            'psos_result'    => $psosResult,
            'probe_runs'     => $this->formatProbeRuns($probeRuns),
        ];
    }

    private function formatProbeRuns($probeRuns): array
    {
        return $probeRuns->map(function ($r) {
            $rc = json_decode($r->raw_config ?? '{}', true);
            return [
                'platform'   => $r->platform,
                'probe_mode' => $r->probe_mode,
                'dit_turn'   => $r->dit_turn ? (int)$r->dit_turn : null,
                'dit_type'   => $rc['dit_type'] ?? null,
                't4_winner'  => $r->t4_winner,
                'handoff'    => $r->handoff_turn ? (int)$r->handoff_turn : null,
                'score'      => (int)($rc['probe_score'] ?? 0),
                'turns'      => (int)$r->turns_completed,
                'termination'=> $r->termination_type ?? 'turn_limit',
            ];
        })->values()->toArray();
    }

    private function fetchExisting(int $brandId): ?array
    {
        $row = DB::table('meridian_brand_audit_results')
            ->where('brand_id', $brandId)
            ->whereNotNull('remediation_json')
            ->orderByDesc('created_at')
            ->first(['remediation_json', 'remediation_generated_at']);

        if (!$row) return null;
        $data = json_decode($row->remediation_json, true);
        if (!$data) return null;
        $data['_generated_at'] = $row->remediation_generated_at;
        return $data;
    }

    /**
     * Write the remediation to the result row for the specific audit used,
     * not just the most recently created result row. This prevents a stale
     * result row from an earlier bad audit being overwritten incorrectly.
     */
    private function store(int $brandId, int $auditId, array $parsed): void
    {
        // First try to find the result row for the specific audit
        $row = DB::table('meridian_brand_audit_results')
            ->where('audit_id', $auditId)
            ->first(['id']);

        // Fall back to most recently created row for this brand
        if (!$row) {
            $row = DB::table('meridian_brand_audit_results')
                ->where('brand_id', $brandId)
                ->orderByDesc('created_at')
                ->first(['id']);
        }

        if ($row) {
            DB::table('meridian_brand_audit_results')
                ->where('id', $row->id)
                ->update([
                    'remediation_json'         => json_encode($parsed),
                    'remediation_generated_at' => now(),
                ]);
        }
    }

    // ── Prompt ────────────────────────────────────────────────────────────────

    private function buildPrompt(array $d): string
    {
        $probesSummary = $this->formatProbesSummary($d['probe_runs']);
        $citationSummary = $d['citation_brief']
            ? json_encode($d['citation_brief'], JSON_PRETTY_PRINT)
            : 'No citation audit data available.';
        $psosSummary = $d['psos_result']
            ? json_encode($d['psos_result'], JSON_PRETTY_PRINT)
            : 'PSOS Baseline not yet run for this brand.';
        $taxonomyList = implode("\n", array_map(
            fn($k, $v) => "  {$k}: {$v}",
            array_keys(self::DISPLACEMENT_TAXONOMY),
            self::DISPLACEMENT_TAXONOMY
        ));
        $platformList = implode("\n", array_map(
            fn($k, $v) => "  " . strtoupper($k) . ": {$v}",
            array_keys(self::PLATFORM_FINGERPRINTS),
            self::PLATFORM_FINGERPRINTS
        ));

        $rcsFormatted = number_format($d['rcs_total'], 1);
        $rarFormatted = $d['revenue_at_risk'] > 0
            ? '£' . number_format($d['revenue_at_risk'] / 1000000, 2) . 'M'
            : 'Not calculated';

        return <<<PROMPT
You are a senior AI brand intelligence analyst. Produce a structured remediation report for the brand below.

Your output MUST be a single valid JSON object with exactly 7 top-level keys. No preamble. No markdown. No explanation. Start with { and end with }.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BRAND
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Brand: {$d['brand_name']}
Category: {$d['brand_category']}
RCS Score: {$rcsFormatted}/100
Overall Verdict: {$d['ad_verdict']}
Revenue at Risk: {$rarFormatted}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BUYING JOURNEY PROBE RESULTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$probesSummary}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CITATION PERSISTENCE DATA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$citationSummary}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PSOS BASELINE DATA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$psosSummary}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
DISPLACEMENT TAXONOMY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$taxonomyList}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PLATFORM FINGERPRINTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$platformList}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REQUIRED JSON OUTPUT — 7 KEYS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

{
  "diagnostic_verdict": {
    "headline": "One sentence capturing the brand's primary AI exposure problem",
    "summary": "2–3 sentences: overall position, primary mechanism, commercial consequence",
    "severity": "critical | high | moderate | low",
    "primary_risk_type": "generic_absence | dit_early | capture | equalisation | drift_arc | runtime_displacement | criterion_loss",
    "rar_context": "One sentence connecting RAR figure to the specific gap identified"
  },
  "platform_displacement_analysis": {
    "chatgpt": {
      "dit": "T1 | T2 | T3 | T4 | null | not_tested",
      "mechanism": "Name the specific displacement mechanism",
      "displacing_competitors": ["Named competitor brands if identifiable"],
      "handoff_captured": true,
      "diagnosis": "2 sentences: what is happening and why",
      "intervention": "1–2 sentences: specific action required"
    },
    "gemini": { "same structure" },
    "perplexity": { "same structure" },
    "grok": { "same structure" }
  },
  "citation_gap_matrix": [
    {
      "tier": "T1 | T2 | T3",
      "source_category": "e.g. Peer-reviewed dermatology journals",
      "current_status": "absent | weak | adequate | strong",
      "commercial_consequence": "One sentence on what this gap costs",
      "recommended_action": "Specific actionable instruction",
      "priority": "immediate | 30-day | 90-day"
    }
  ],
  "psos_intervention_priority": {
    "overall_band": "Fragile | Moderate | Strong | Not tested",
    "weakest_dimension": "Breadth | Depth | Resilience | Sentiment | Decay | Not tested",
    "fragility_finding": "1–2 sentences on prompt dependency",
    "priority_interventions": [
      { "dimension": "string", "intervention": "string", "rationale": "string" }
    ]
  },
  "sequenced_programme": [
    {
      "phase": 1,
      "label": "Foundation — citation architecture",
      "timeline": "Weeks 1–4",
      "actions": ["Specific actionable steps"],
      "t1_t2_t3_focus": "Which citation tier this phase addresses",
      "dependency": "What this phase enables next",
      "expected_metric_change": "What should move and by how much"
    },
    { "phase": 2, "label": "DIT delay", "timeline": "Weeks 5–8", "actions": [], "t1_t2_t3_focus": "", "dependency": "", "expected_metric_change": "" },
    { "phase": 3, "label": "Platform-specific remediation", "timeline": "Weeks 9–12", "actions": [], "t1_t2_t3_focus": "", "dependency": "", "expected_metric_change": "" }
  ],
  "reaudit_schedule": {
    "week_4_checkpoint": "What to test and what signal to look for",
    "week_8_checkpoint": "What to test and what signal to look for",
    "quarter_1_full_audit": "Which instruments to run and what the decision gate is",
    "early_warning_triggers": ["Conditions that should prompt unscheduled re-audit"],
    "competitive_monitoring_priority": "Which competitor(s) to watch and on which platform"
  },
  "reasoning_pattern_classification": {
    "primary_pattern": "Name from displacement taxonomy",
    "pattern_severity": "critical | high | moderate | low",
    "pattern_description": "2–3 sentences on how this pattern operates for this brand",
    "cascade_position": "Where brand sits in AI category hierarchy",
    "secondary_patterns": [
      { "pattern": "string", "platform": "string", "description": "string" }
    ],
    "structural_insight": "One sharp observation about the brand's AI positioning problem"
  }
}

Rules:
- Be specific to this brand. No generic advice.
- Name competitors where identifiable from the probe data.
- Use "not_tested" where data is absent — do not fabricate.
- Severity: critical = DIT T2 on 3+ platforms or CAPTURE documented. high = generic probe absence + DIT T3 or earlier. moderate = DIT T4–T6. low = DIT T7+ or null.
- Output JSON only.
PROMPT;
    }

    private function formatProbesSummary(array $runs): string
    {
        if (empty($runs)) return 'No probe run data available.';
        $lines = [];
        foreach ($runs as $r) {
            $platform = strtoupper($r['platform']);
            $mode     = $r['probe_mode'];
            $dit      = $r['dit_turn'] !== null ? 'T' . $r['dit_turn'] : 'null';
            $t4       = $r['t4_winner'] ?? 'none';
            $lines[]  = "{$platform} ({$mode}): DIT={$dit}, T4_winner={$t4}, score={$r['score']}, turns={$r['turns']}";
        }
        return implode("\n", $lines);
    }

    // ── Claude API ────────────────────────────────────────────────────────────

    private function callClaude(string $prompt): array
    {
        if (empty($this->claudeApiKey)) {
            return ['success' => false, 'error' => 'ANTHROPIC_API_KEY not set'];
        }

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 4000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeApiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) return ['success' => false, 'error' => "cURL: {$curlErr}"];
        if ($httpCode !== 200) return ['success' => false, 'error' => "Claude API HTTP {$httpCode}"];

        $decoded = json_decode($response, true);
        $content = $decoded['content'][0]['text'] ?? null;
        if (!$content) return ['success' => false, 'error' => 'Empty Claude response'];

        return ['success' => true, 'content' => $content];
    }

    private function parseJson(string $raw): ?array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $clean = trim($clean);

        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false) return null;

        $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        $required = ['diagnostic_verdict','platform_displacement_analysis','citation_gap_matrix',
                     'psos_intervention_priority','sequenced_programme','reaudit_schedule',
                     'reasoning_pattern_classification'];
        foreach ($required as $k) {
            if (!isset($parsed[$k])) return null;
        }

        return $parsed;
    }
}
