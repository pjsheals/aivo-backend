<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianProbeEngine
 *
 * Executes a single DPA (Decision-stage Probe Analysis) probe run.
 * Called by the background worker — not from HTTP context.
 *
 * One probe run = one platform × one probe mode (anchored or generic).
 * Four turns per probe: T1 awareness, T2 comparison, T3 criteria, T4 purchase.
 */
class MeridianProbeEngine
{
    // Internal proxy URL — localhost avoids public internet round-trip
    private string $proxyUrl;
    private string $claudeApiKey;
    private string $anthropicVersion = '2023-06-01';

    // Acceptance phrases for undirected mode (reserved for future use)
    private array $acceptPhrases = [
        'Yes please', 'Go ahead', 'Sounds good, tell me more',
        'Sure, that would be helpful', 'Yes, I\'d like to know more about that',
        'Please continue', 'That sounds interesting, tell me more', 'Yes',
    ];

    public function __construct()
    {
        $port           = getenv('PORT') ?: '80';
        $this->proxyUrl = 'http://localhost:' . $port . '/api/proxy';
        $this->claudeApiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    }

    // ── MAIN ENTRY POINT ─────────────────────────────────────────
    /**
     * Execute one probe run. Updates DB throughout.
     * Returns true on success, false on fatal failure.
     */
    public function run(int $probeRunId): bool
    {
        // Load probe run + audit + brand
        $run = DB::table('meridian_probe_runs')->find($probeRunId);
        if (!$run) {
            error_log('[ProbeEngine] probe_run not found: ' . $probeRunId);
            return false;
        }

        $audit = DB::table('meridian_audits')->find($run->audit_id);
        if (!$audit) {
            error_log('[ProbeEngine] audit not found: ' . $run->audit_id);
            return false;
        }

        $brand = DB::table('meridian_brands')->find($run->brand_id);
        if (!$brand) {
            error_log('[ProbeEngine] brand not found: ' . $run->brand_id);
            return false;
        }

        // Prompts are stored in raw_config on the probe run (no prompts col on audits)
        $rawConfig = json_decode($run->raw_config ?? '{}', true);
        $prompts   = $rawConfig['prompts']    ?? [];
        $brandName = $rawConfig['brand_name'] ?? $brand->name;
        $category  = $rawConfig['category']   ?? ($brand->category ?: 'product');
        $platform  = $run->platform;
        $mode      = $run->probe_mode; // 'anchored' or 'generic'

        // Mark as running
        DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
            'status'     => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        // Build 4-turn prompt sequence
        $turnPrompts = $this->buildTurnPrompts($mode, $prompts, $brandName, $category);
        $totalTurns  = 4;

        $messages  = [];
        $turns     = [];
        $ditTurn   = null;
        $t4Winner  = null;
        $t4WinnerConfidence = null;

        try {
            for ($t = 0; $t < $totalTurns; $t++) {
                $turnNum   = $t + 1;
                $userMsg   = $turnPrompts[$t];
                $isFinal   = ($turnNum === $totalTurns);

                error_log("[ProbeEngine] {$platform}/{$mode} T{$turnNum} — calling model");
                $messages[] = ['role' => 'user', 'content' => $userMsg];

                // Call model via proxy (with retry)
                $modelResult = $this->callModelWithRetry($messages, $platform);

                if ($modelResult === null) {
                    // Fatal model failure on this turn
                    $turns[] = [
                        'turn_number'     => $turnNum,
                        'user_prompt'     => $userMsg,
                        'model_response'  => '',
                        'citation_urls'   => json_encode(['urls' => [], 'annotation' => null]),
                        'is_dit_turn'     => false,
                        'is_handoff_turn' => ($turnNum === $totalTurns),
                        'brand_presence'  => 'absent',
                        '_brand_survived' => false,
                        '_annotation'     => null,
                        '_error'          => 'Model call failed after retry',
                    ];
                    // For T1/T2 failures, abort this probe
                    if ($turnNum <= 2) {
                        throw new \RuntimeException("Model failure at T{$turnNum} — aborting probe");
                    }
                    continue;
                }

                $responseText = $modelResult['text'];
                $citationUrls = $modelResult['citations'] ?? [];

                $messages[] = ['role' => 'assistant', 'content' => $responseText];

                // Annotate turn with Claude
                error_log("[ProbeEngine] {$platform}/{$mode} T{$turnNum} — annotating");
                $annotation = $this->annotate(
                    $responseText, $brandName, $category,
                    $turnNum, $totalTurns, $platform, false, $citationUrls
                );

                // Extract T4 winner
                if ($isFinal && $annotation) {
                    $t4Winner           = $annotation['t4_winner'] ?? null;
                    $t4WinnerConfidence = $annotation['t4_winner_confidence'] ?? null;
                }

                // Compute DIT — first turn brand_citation_survived goes false
                $brandSurvived = (bool)($annotation['brand_citation_survived'] ?? true);
                $ditFired      = false;
                if (!$brandSurvived && $ditTurn === null) {
                    $ditTurn  = $turnNum;
                    $ditFired = true;
                }

                $turns[] = [
                    'turn_number'      => $turnNum,
                    'user_prompt'      => $userMsg,
                    'model_response'   => $responseText,
                    'citation_urls'    => json_encode([
                        'urls'       => $citationUrls,
                        'annotation' => $annotation,
                    ]),
                    'is_dit_turn'      => $ditFired,
                    'is_handoff_turn'  => $isFinal,
                    'brand_presence'   => $brandSurvived ? 'present' : 'absent',
                    // Internal only — used for scoring, not stored as separate column
                    '_brand_survived'  => $brandSurvived,
                    '_annotation'      => $annotation,
                    '_error'           => null,
                ];

                // Update turns_completed counter
                DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
                    'turns_completed' => $turnNum,
                    'updated_at'      => now(),
                ]);
            }

            // Save all turns to DB — columns match meridian_probe_turns schema
            foreach ($turns as $turn) {
                DB::table('meridian_probe_turns')->insert([
                    'probe_run_id'   => $probeRunId,
                    'audit_id'       => $run->audit_id,
                    'agency_id'      => $run->agency_id,
                    'brand_id'       => $run->brand_id,
                    'turn_number'    => $turn['turn_number'],
                    'user_prompt'    => $turn['user_prompt'],
                    'model_response' => $turn['model_response'],
                    'citation_urls'  => $turn['citation_urls'],
                    'is_dit_turn'    => $turn['is_dit_turn'],
                    'is_handoff_turn'=> $turn['is_handoff_turn'],
                    'brand_presence' => $turn['brand_presence'],
                    'created_at'     => now(),
                ]);
            }

            // Compute probe-level score (CODA weighting)
            $probeScore = $this->computeProbeScore($turns);

            // Determine DIT type from annotation
            $ditType = null;
            if ($ditTurn !== null) {
                $ditTurnData = collect($turns)->firstWhere('turn_number', $ditTurn);
                $anno        = $ditTurnData['_annotation'] ?? [];
                $ditType     = $this->inferDitType($anno);
            }

            // Mark probe complete — columns match meridian_probe_runs schema
            DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
                'status'           => 'completed',
                'turns_completed'  => $totalTurns,
                'dit_turn'         => $ditTurn,
                'handoff_turn'     => $totalTurns, // T4 is always the handoff turn
                't4_winner'        => $t4Winner,
                'termination_type' => 'turn_limit',
                'raw_config'       => json_encode(array_merge(
                    json_decode($run->raw_config ?? '{}', true),
                    [
                        'probe_score' => $probeScore,
                        'dit_type'    => $ditType,
                        't4_winner_confidence' => $t4WinnerConfidence,
                    ]
                )),
                'completed_at'     => now(),
                'updated_at'       => now(),
            ]);

            error_log("[ProbeEngine] {$platform}/{$mode} complete. Score={$probeScore} DIT={$ditTurn} T4={$t4Winner}");
            return true;

        } catch (\Throwable $e) {
            error_log('[ProbeEngine] probe failed: ' . $e->getMessage());
            DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
                'updated_at'    => now(),
            ]);
            return false;
        }
    }

    // ── BUILD PROMPT SEQUENCE ────────────────────────────────────
    private function buildTurnPrompts(string $mode, array $prompts, string $brandName, string $category): array
    {
        $prefix = $mode === 'anchored' ? 'anchored_' : 'generic_';
        return [
            $prompts[$prefix . 't1'] ?? "Tell me about {$brandName} for {$category}.",
            $prompts[$prefix . 't2'] ?? "How does it compare to alternatives at a similar price?",
            $prompts[$prefix . 't3'] ?? "I need something with proven ingredients, visible results, good reviews, value for money, and wide availability. Which option fits best?",
            $prompts[$prefix . 't4'] ?? "Based on everything we've discussed, what would you recommend I buy and where can I get it from?",
        ];
    }

    // ── MODEL CALL ───────────────────────────────────────────────
    private function callModelWithRetry(array $messages, string $platform, int $maxRetries = 2): ?array
    {
        // Keep window to last 7 messages (T1 + last 3 pairs) to avoid size limits
        $trimmed = $this->trimMessages($messages, 3);

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if ($attempt > 0) {
                error_log("[ProbeEngine] retry {$attempt} for {$platform}");
                sleep(5);
            }
            $result = $this->callProxy($trimmed, $platform);
            if ($result !== null) return $result;
        }
        return null;
    }

    private function trimMessages(array $messages, int $keepPairs = 3): array
    {
        if (count($messages) <= $keepPairs * 2 + 1) return $messages;
        $first  = array_slice($messages, 0, 1);
        $recent = array_slice($messages, -($keepPairs * 2));
        return array_merge($first, $recent);
    }

    private function callProxy(array $messages, string $platform): ?array
    {
        $system  = 'You are a helpful AI assistant responding to consumer questions about products, brands and purchasing decisions. Respond naturally and helpfully.';
        $payload = json_encode([
            'platform'   => $platform,
            'messages'   => $messages,
            'system'     => $system,
            'max_tokens' => 1800,
        ]);

        $ch = curl_init($this->proxyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 90,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw || $httpCode < 200 || $httpCode >= 300) {
            error_log("[ProbeEngine] proxy error HTTP={$httpCode} err={$err}");
            return null;
        }

        // Proxy returns SSE stream — parse it
        return $this->parseSseStream($raw);
    }

    private function parseSseStream(string $raw): ?array
    {
        $text      = '';
        $citations = [];
        $lines     = explode("\n", $raw);

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) continue;
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') continue;

            $obj = json_decode($data, true);
            if (!$obj) continue;

            $chunk = $obj['choices'][0]['delta']['content'] ?? null;
            if ($chunk) $text .= $chunk;

            // Perplexity citation fields
            if (!empty($obj['citations'])) $citations = $obj['citations'];
            if (!empty($obj['choices'][0]['delta']['citations'])) $citations = $obj['choices'][0]['delta']['citations'];
            if (!empty($obj['search_results'])) {
                $citations = array_map(fn($r) => $r['url'] ?? '', $obj['search_results']);
                $citations = array_filter($citations);
            }
        }

        if (!$text) {
            error_log('[ProbeEngine] empty response from SSE stream');
            return null;
        }

        return ['text' => $text, 'citations' => array_values($citations)];
    }

    // ── ANNOTATION ───────────────────────────────────────────────
    private function annotate(
        string $responseText,
        string $brand,
        string $category,
        int    $turnNum,
        int    $totalTurns,
        string $platform,
        bool   $isUndirected,
        array  $citationUrls
    ): ?array {
        if (!$this->claudeApiKey) {
            error_log('[ProbeEngine] ANTHROPIC_API_KEY not set — skipping annotation');
            return null;
        }

        $turnContext = "This is turn {$turnNum} of a {$totalTurns}-turn directed DPA probe: T1=baseline perception, T2=comparison expansion, T3=five-criteria optimisation (displacement trigger), T4=purchase decision.";

        $urlCtx = '';
        if (!empty($citationUrls)) {
            $urlCtx = "\n\nActual URLs retrieved by {$platform}:\n";
            foreach (array_slice($citationUrls, 0, 8) as $i => $url) {
                $urlCtx .= ($i + 1) . ". {$url}\n";
            }
        }

        $isFinal  = ($turnNum === $totalTurns);
        $t4Context = $isFinal
            ? "\n\nThis is the PURCHASE DECISION turn. Extract: (1) which brand is explicitly recommended for purchase — this is the T4 winner even if it is not {$brand}; (2) destination quality — where the consumer is routed for purchase; (3) whether {$brand} is present at all."
            : '';

        $prompt = <<<PROMPT
Analyse this AI response about "{$brand}" ({$category}).

{$turnContext}{$urlCtx}{$t4Context}

Response:
"""{$this->truncate($responseText, 2200)}"""

Return ONLY valid JSON, no markdown:
{
  "source_types":[{
    "type":"reference_authority|brand_editorial|clinical_science|community_forum|brand_owned|retail_commerce|general_web",
    "confidence":"high|medium|low",
    "evidence":"one sentence explaining what in the response indicates this source type",
    "brand_cited_here":true/false,
    "likely_sources":["specific website/publication names"]
  }],
  "dominant_type":"most influential source type this turn",
  "brand_citation_survived":true/false,
  "displacement_signal":"none|partial|complete",
  "journey_stage":"awareness|comparison|criteria|purchase",
  "key_finding":"one sentence identifying the most important citation insight for this turn",
  "t4_winner":PLACEHOLDER_WINNER,
  "t4_winner_confidence":PLACEHOLDER_CONFIDENCE,
  "destination_type":PLACEHOLDER_DEST_TYPE,
  "destination_sites":PLACEHOLDER_DEST_SITES
}
PROMPT;

        // Fill T4 placeholders
        if ($isFinal) {
            $prompt = str_replace(
                ['PLACEHOLDER_WINNER', 'PLACEHOLDER_CONFIDENCE', 'PLACEHOLDER_DEST_TYPE', 'PLACEHOLDER_DEST_SITES'],
                [
                    '"the brand name recommended for purchase — null if none"',
                    '"high|medium|low"',
                    '"brand_com|retailer|community|editorial|clinical|mixed|none"',
                    '["specific sites named for purchase"]',
                ],
                $prompt
            );
        } else {
            $prompt = str_replace(
                ['PLACEHOLDER_WINNER', 'PLACEHOLDER_CONFIDENCE', 'PLACEHOLDER_DEST_TYPE', 'PLACEHOLDER_DEST_SITES'],
                ['null', 'null', 'null', '[]'],
                $prompt
            );
        }

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeApiKey,
                'anthropic-version: ' . $this->anthropicVersion,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $httpCode !== 200) {
            error_log("[ProbeEngine] annotation failed HTTP={$httpCode}");
            return null;
        }

        $data = json_decode($raw, true);
        $text = $data['content'][0]['text'] ?? '';

        // Strip markdown fences if present
        $text = preg_replace('/^```json\s*/m', '', $text);
        $text = preg_replace('/^```\s*/m', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);
        if (!$result) {
            error_log('[ProbeEngine] annotation JSON parse failed: ' . substr($text, 0, 200));
            return null;
        }

        return $result;
    }

    // ── SCORING ──────────────────────────────────────────────────
    /**
     * CODA scoring: T1=15, T2=15, T3=20, T4=50 points (max 100 per probe)
     * Score based on brand_citation_survived per turn.
     */
    public function computeProbeScore(array $turns): int
    {
        $weights = [1 => 15, 2 => 15, 3 => 20, 4 => 50];
        $score   = 0;

        foreach ($turns as $turn) {
            $t        = (int)($turn['turn_number'] ?? 0);
            $survived = (bool)($turn['_brand_survived'] ?? false);
            if ($survived && isset($weights[$t])) {
                $score += $weights[$t];
            }
        }

        return $score;
    }

    /**
     * Overall RCS from all completed probe runs for an audit.
     * Weighted: anchored probes × 0.7, generic probes × 0.3
     * Average across platforms.
     */
    public function computeAuditRcs(int $auditId): int
    {
        $runs = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->where('status', 'completed')
            ->get();

        if ($runs->isEmpty()) return 0;

        // probe_score is stored in raw_config jsonb (no dedicated column)
        $anchoredScores = [];
        $genericScores  = [];

        foreach ($runs as $run) {
            $rc    = json_decode($run->raw_config ?? '{}', true);
            $score = $rc['probe_score'] ?? null;
            if ($score === null) continue;

            if ($run->probe_mode === 'anchored') {
                $anchoredScores[] = (int)$score;
            } else {
                $genericScores[] = (int)$score;
            }
        }

        $anchoredAvg = count($anchoredScores) > 0 ? array_sum($anchoredScores) / count($anchoredScores) : 0;
        $genericAvg  = count($genericScores)  > 0 ? array_sum($genericScores)  / count($genericScores)  : 0;

        $rcs = ($anchoredAvg * 0.70) + ($genericAvg * 0.30);
        return (int)round($rcs);
    }

    public function determineVerdict(int $rcs): string
    {
        if ($rcs >= 70) return 'amplification_ready';
        if ($rcs >= 40) return 'monitor';
        return 'do_not_advertise';
    }

    // ── BRIEF GENERATION ─────────────────────────────────────────
    public function generateBrief(string $brandName, string $category, int $auditId): ?array
    {
        if (!$this->claudeApiKey) return null;

        // Gather T4 data per platform
        $runs = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->where('status', 'completed')
            ->get();

        $t4Summary = [];
        foreach ($runs as $run) {
            $turns = DB::table('meridian_probe_turns')
                ->where('probe_run_id', $run->id)
                ->orderBy('turn_number')
                ->get();

            $t4 = $turns->firstWhere('turn_number', 4) ?? $turns->last();
            $t1 = $turns->firstWhere('turn_number', 1);
            if (!$t4) continue;

            // annotation stored in citation_urls jsonb as {'urls':[], 'annotation':{}}
            $t4CitData = json_decode($t4->citation_urls ?? '{}', true);
            $t1CitData = json_decode($t1->citation_urls ?? '{}', true);
            $t4Anno = $t4CitData['annotation'] ?? [];
            $t1Anno = $t1CitData['annotation'] ?? [];

            $t4Summary[] = [
                'platform'         => $run->platform,
                'probe_mode'       => $run->probe_mode,
                't1_dominant'      => $t1Anno['dominant_type'] ?? null,
                't1_brand_cited'   => ($t1->brand_presence ?? 'present') === 'present',
                't4_dominant'      => $t4Anno['dominant_type'] ?? null,
                't4_brand_cited'   => ($t4->brand_presence ?? 'absent') === 'present',
                't4_displacement'  => $t4Anno['displacement_signal'] ?? 'none',
                't4_source_types'  => array_map(fn($s) => [
                    'type'        => $s['type'] ?? '',
                    'brand_cited' => $s['brand_cited_here'] ?? false,
                    'sources'     => $s['likely_sources'] ?? [],
                ], $t4Anno['source_types'] ?? []),
                't4_key_finding'   => $t4Anno['key_finding'] ?? null,
            ];
        }

        $summary = json_encode(['brand' => $brandName, 'category' => $category, 'platforms' => $t4Summary], JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
You are a citation architecture strategist. Analyse this DPA (Decision-stage Probe Analysis) result for "{$brandName}" ({$category}).

CRITICAL: Focus on T4 — the purchase decision turn. t4_brand_cited=false means the brand was displaced at the moment that determines who wins the sale.

{$summary}

Return ONLY valid JSON:
{
  "key_finding": "one sentence about what happens at T4 — not T1",
  "t_final_winning_sources": ["source types dominant at T4"],
  "brand_gaps": ["T4 source types where brand_cited is false"],
  "interventions": [{
    "priority": 1,
    "action": "specific action targeting the T4 citation gap",
    "target_type": "source type key",
    "target_publications": ["specific publication names"],
    "rationale": "why this closes the T4 gap",
    "timeline": "realistic timeline"
  }],
  "profound_contrast": "what a first-prompt visibility tool would show vs what T4 displacement reveals"
}
PROMPT;

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1200,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeApiKey,
                'anthropic-version: ' . $this->anthropicVersion,
            ],
            CURLOPT_TIMEOUT => 45,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $httpCode !== 200) return null;

        $data = json_decode($raw, true);
        $text = $data['content'][0]['text'] ?? '';
        $text = trim(preg_replace('/^```(json)?\s*/m', '', $text));
        $text = trim(preg_replace('/^```\s*$/m', '', $text));

        return json_decode($text, true) ?: null;
    }

    // ── HELPERS ──────────────────────────────────────────────────
    private function inferDitType(array $annotation): ?string
    {
        $sourceTypes = $annotation['source_types'] ?? [];
        $dominant    = $annotation['dominant_type'] ?? '';

        // Clinical science displacement = evaluative (criteria-based)
        if (in_array($dominant, ['clinical_science'], true)) return 'evaluative';
        // Community forum = comparative (peer comparison)
        if (in_array($dominant, ['community_forum'], true)) return 'comparative';
        // If multiple types without brand, it's multi-axis
        $nonBrandTypes = array_filter($sourceTypes, fn($s) => !($s['brand_cited_here'] ?? true));
        if (count($nonBrandTypes) >= 2) return 'multi_axis';

        return 'comparative';
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
