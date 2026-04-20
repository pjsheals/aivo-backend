<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

class MeridianProbeEngine
{
    private string $proxyUrl;
    private string $claudeApiKey;
    private string $anthropicVersion = '2023-06-01';

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

    public function run(int $probeRunId): bool
    {
        $run = DB::table('meridian_probe_runs')->find($probeRunId);
        if (!$run) { error_log('[ProbeEngine] probe_run not found: ' . $probeRunId); return false; }

        $audit = DB::table('meridian_audits')->find($run->audit_id);
        if (!$audit) { error_log('[ProbeEngine] audit not found: ' . $run->audit_id); return false; }

        $brand = DB::table('meridian_brands')->find($run->brand_id);
        if (!$brand) { error_log('[ProbeEngine] brand not found: ' . $run->brand_id); return false; }

        $rawConfig = json_decode($run->raw_config ?? '{}', true);
        $prompts   = $rawConfig['prompts']    ?? [];
        $brandName = $rawConfig['brand_name'] ?? $brand->name;
        $category  = $rawConfig['category']   ?? ($brand->category ?: 'product');
        $platform  = $run->platform;
        $mode      = $run->probe_mode;

        DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
            'status'     => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $isUndirected = ($rawConfig['undirected'] ?? false) === true;
        $maxTurns     = $isUndirected ? (int)($rawConfig['max_turns'] ?? 8) : 4;

        $turnPrompts = $isUndirected
            ? null
            : $this->buildTurnPrompts($mode, $prompts, $brandName, $category);

        $t1Prompt = $isUndirected
            ? ($prompts['undirected_t1'] ?? "I've been looking at {$brandName} for {$category}. Can you tell me about it?")
            : null;

        $totalTurns = $maxTurns;
        $messages   = [];
        $turns      = [];
        $ditTurn    = null;
        $t4Winner   = null;
        $t4WinnerConfidence = null;
        $consecutiveConversionLoops = 0;

        try {
            for ($t = 0; $t < $totalTurns; $t++) {
                $turnNum = $t + 1;
                $isFinal = ($turnNum === $totalTurns);

                if ($isUndirected) {
                    $userMsg = ($t === 0) ? $t1Prompt : $this->randomAcceptPhrase();
                } else {
                    $userMsg = $turnPrompts[$t];
                }

                error_log("[ProbeEngine] {$platform}/{$mode} T{$turnNum} — calling model");
                $messages[] = ['role' => 'user', 'content' => $userMsg];

                $modelResult = $this->callModelWithRetry($messages, $platform, $isUndirected);

                if ($modelResult === null) {
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
                    if ($turnNum <= 2) {
                        throw new \RuntimeException("Model failure at T{$turnNum} — aborting probe");
                    }
                    continue;
                }

                $responseText = $modelResult['text'];
                $citationUrls = $modelResult['citations'] ?? [];
                $messages[]   = ['role' => 'assistant', 'content' => $responseText];

                error_log("[ProbeEngine] {$platform}/{$mode} T{$turnNum} — annotating");
                $annotation = $this->annotate(
                    $responseText, $brandName, $category,
                    $turnNum, $totalTurns, $platform, $isUndirected, $citationUrls
                );

                if ($annotation === null) {
                    error_log("[ProbeEngine] annotation null at T{$turnNum} {$platform}/{$mode} — treating brand as absent");
                }
                $brandSurvived = (bool)($annotation['brand_citation_survived'] ?? false);

                if ($isFinal && $annotation) {
                    $t4Winner           = $annotation['t4_winner'] ?? null;
                    $t4WinnerConfidence = $annotation['t4_winner_confidence'] ?? null;
                }

                $ditFired = false;
                if (!$brandSurvived && $ditTurn === null) {
                    $ditTurn  = $turnNum;
                    $ditFired = true;
                }

                if ($isUndirected && $annotation) {
                    $stage = $annotation['journey_stage'] ?? '';
                    if (in_array($stage, ['purchase', 'channel'], true)) {
                        $consecutiveConversionLoops++;
                    } else {
                        $consecutiveConversionLoops = 0;
                    }
                }

                $isAcceptPhrase = $isUndirected && $t > 0;

                $turns[] = [
                    'turn_number'          => $turnNum,
                    'user_prompt'          => $userMsg,
                    'model_response'       => $responseText,
                    'citation_urls'        => json_encode(['urls' => $citationUrls, 'annotation' => $annotation]),
                    'is_dit_turn'          => $ditFired,
                    'is_handoff_turn'      => $isFinal,
                    'is_acceptance_phrase' => $isAcceptPhrase,
                    'brand_presence'       => $brandSurvived ? 'present' : 'absent',
                    '_brand_survived'      => $brandSurvived,
                    '_annotation'          => $annotation,
                    '_error'              => null,
                ];

                if ($isUndirected && $annotation) {
                    $stage  = $annotation['journey_stage'] ?? '';
                    $signal = $annotation['displacement_signal'] ?? 'none';
                    if ($stage === 'purchase' && $signal === 'complete' && $t >= 3) {
                        error_log("[ProbeEngine] Undirected early termination: purchase conclusion at T{$turnNum}");
                        $isFinal = true;
                        break;
                    }
                    if ($consecutiveConversionLoops >= 4) {
                        error_log("[ProbeEngine] Undirected early termination: 4 consecutive conversion loops at T{$turnNum}");
                        $isFinal = true;
                        break;
                    }
                }

                DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
                    'turns_completed' => $turnNum,
                    'updated_at'      => now(),
                ]);
            }

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

            $probeScore = $this->computeProbeScore($turns);

            $ditType = null;
            if ($ditTurn !== null) {
                $ditTurnData = collect($turns)->firstWhere('turn_number', $ditTurn);
                $anno        = $ditTurnData['_annotation'] ?? [];
                $ditType     = $this->inferDitType($anno);
            }

            DB::table('meridian_probe_runs')->where('id', $probeRunId)->update([
                'status'           => 'completed',
                'turns_completed'  => $totalTurns,
                'dit_turn'         => $ditTurn,
                'handoff_turn'     => $totalTurns,
                't4_winner'        => $t4Winner,
                'termination_type' => 'turn_limit',
                'raw_config'       => json_encode(array_merge(
                    json_decode($run->raw_config ?? '{}', true),
                    [
                        'probe_score'          => $probeScore,
                        'dit_type'             => $ditType,
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
            // T1: from user-supplied prompt (always required)
            $prompts[$prefix . 't1'] ?? "Tell me about {$brandName} for {$category}.",
            // T2: comparison — category-agnostic fallback
            $prompts[$prefix . 't2'] ?? "How does it compare to other options available in this space?",
            // T3: criteria — category-agnostic fallback (no skincare language)
            $prompts[$prefix . 't3'] ?? "I need to make a decision. Thinking about proven effectiveness, value for money, reputation, and ease of access — which option would you recommend?",
            // T4: purchase — category-agnostic fallback
            $prompts[$prefix . 't4'] ?? "Based on everything we've discussed, what would you recommend I go with and where can I get it?",
        ];
    }

    // ── MODEL CALL ───────────────────────────────────────────────

    /**
     * Call model with retry. Gemini uses a longer retry delay (15s vs 5s)
     * because it rate-limits more aggressively than ChatGPT/Perplexity,
     * especially when multiple audits run concurrently.
     */
    private function callModelWithRetry(array $messages, string $platform, bool $isUndirected = false, int $maxRetries = 2): ?array
    {
        $trimmed    = $this->trimMessages($messages, 3);
        $retryDelay = ($platform === 'gemini') ? 15 : 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if ($attempt > 0) {
                error_log("[ProbeEngine] retry {$attempt} for {$platform} (delay {$retryDelay}s)");
                sleep($retryDelay);
            }
            $result = $this->callProxy($trimmed, $platform);
            if ($result !== null) return $result;
        }

        error_log("[ProbeEngine] {$platform} failed after {$maxRetries} attempts");
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

        return $this->parseSseStream($raw, $platform);
    }

    private function parseSseStream(string $raw, string $platform = ''): ?array
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

            if (!empty($obj['citations']))                             $citations = $obj['citations'];
            if (!empty($obj['choices'][0]['delta']['citations']))   $citations = $obj['choices'][0]['delta']['citations'];
            if (!empty($obj['search_results'])) {
                $citations = array_filter(array_map(fn($r) => $r['url'] ?? '', $obj['search_results']));
            }
        }

        if (!$text) {
            error_log("[ProbeEngine] empty SSE response from {$platform} — raw length=" . strlen($raw));
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

        // Turn context is instrument-aware:
        // Directed probes have fixed turn purposes (T3 = criteria/displacement trigger).
        // Agentic/undirected probes are AI-driven — there is no fixed T3 trigger point.
        // Using directed language for agentic probes produces misleading findings.
        if ($isUndirected) {
            $turnContext = "This is turn {$turnNum} of a {$totalTurns}-turn agentic probe where the AI drives the conversation. "
                . "The consumer's opening message was the only scripted turn — all subsequent turns used acceptance phrases. "
                . "There is no fixed displacement trigger turn. The DIT (Displacement Initiation Turn) is wherever "
                . "the brand first loses primary position, which can occur at any turn.";
        } else {
            $turnContext = "This is turn {$turnNum} of a {$totalTurns}-turn directed DPA probe: "
                . "T1=baseline perception, T2=comparison expansion, T3=criteria evaluation, T4=purchase decision.";
        }

        $urlCtx = '';
        if (!empty($citationUrls)) {
            $urlCtx = "\n\nActual URLs retrieved by {$platform}:\n";
            foreach (array_slice($citationUrls, 0, 8) as $i => $url) {
                $urlCtx .= ($i + 1) . ". {$url}\n";
            }
        }

        $isFinal   = ($turnNum === $totalTurns);
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
  "key_finding":"one sentence identifying the most important citation insight for this turn — do not reference 'T3 displacement trigger' for agentic probes",
  "t4_winner":PLACEHOLDER_WINNER,
  "t4_winner_confidence":PLACEHOLDER_CONFIDENCE,
  "destination_type":PLACEHOLDER_DEST_TYPE,
  "destination_sites":PLACEHOLDER_DEST_SITES
}
PROMPT;

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

        $data   = json_decode($raw, true);
        $text   = $data['content'][0]['text'] ?? '';
        $text   = preg_replace('/^```json\s*/m', '', $text);
        $text   = preg_replace('/^```\s*/m', '', $text);
        $text   = trim($text);
        $result = json_decode($text, true);

        if (!$result) {
            error_log('[ProbeEngine] annotation JSON parse failed: ' . substr($text, 0, 200));
            return null;
        }

        return $result;
    }

    // ── SCORING ──────────────────────────────────────────────────
    public function computeProbeScore(array $turns): int
    {
        $weights = [1 => 15, 2 => 15, 3 => 20, 4 => 50];
        $score   = 0;
        foreach ($turns as $turn) {
            $t        = (int)($turn['turn_number'] ?? 0);
            $survived = (bool)($turn['_brand_survived'] ?? false);
            if ($survived && isset($weights[$t])) $score += $weights[$t];
        }
        return $score;
    }

    public function computeAuditRcs(int $auditId): int
    {
        $runs = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->where('status', 'completed')
            ->get();

        if ($runs->isEmpty()) return 0;

        $anchoredScores = [];
        $genericScores  = [];

        foreach ($runs as $run) {
            $rc    = json_decode($run->raw_config ?? '{}', true);
            $score = $rc['probe_score'] ?? null;
            if ($score === null) continue;
            if ($run->probe_mode === 'anchored') $anchoredScores[] = (int)$score;
            else $genericScores[] = (int)$score;
        }

        $anchoredAvg = count($anchoredScores) > 0 ? array_sum($anchoredScores) / count($anchoredScores) : 0;
        $genericAvg  = count($genericScores)  > 0 ? array_sum($genericScores)  / count($genericScores)  : 0;

        return (int)round(($anchoredAvg * 0.70) + ($genericAvg * 0.30));
    }

    public function determineVerdict(int $rcs): string
    {
        if ($rcs >= 70) return 'amplification_ready';
        if ($rcs >= 40) return 'advertise_with_caution';
        return 'do_not_advertise';
    }

    // ── BRIEF GENERATION ─────────────────────────────────────────
    public function generateBrief(string $brandName, string $category, int $auditId): ?array
    {
        if (!$this->claudeApiKey) return null;

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

            $t4CitData = json_decode($t4->citation_urls ?? '{}', true);
            $t1CitData = json_decode($t1->citation_urls ?? '{}', true);
            $t4Anno    = $t4CitData['annotation'] ?? [];
            $t1Anno    = $t1CitData['annotation'] ?? [];

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
You are a senior citation architecture strategist at an AI measurement agency. Analyse this multi-platform DPA (Decision-stage Probe Analysis) result for "{$brandName}" ({$category}).

CONTEXT: DPA runs a 4-turn buying conversation: T1=awareness, T2=comparison, T3=five-criteria optimisation, T4=purchase decision. DIT (Displacement Initiation Turn) marks when the brand loses primary citation position. T4 winner is the brand that captures the purchase recommendation.

PLATFORM REASONING PATTERNS (apply these when building platform-specific interventions):
- ChatGPT: Training data citations. Displacement is structural. T1/T2 citation architecture interventions take 8-16 weeks to propagate. T3 volatile content has no effect.
- Gemini: Educational Drift Arc pattern. Displaces via educational/authority framing at T2-T3. Requires brand-specific content density and authority evidence in training data. Different fix from ChatGPT.
- Perplexity: Live web retrieval. Displacement is volatile — can be addressed in 2-4 weeks via T3 content. Citation URLs are direct evidence of what is driving displacement.
- Grok: Requires primary criterion ownership and definitional authority.

DATA:
{$summary}

INSTRUCTIONS:
1. Identify the specific competitor(s) winning at T4 by platform — name them explicitly
2. Identify the exact source types and named publications driving each competitor's T4 win
3. Build platform-specific intervention routes — the fix for ChatGPT and Gemini are structurally different
4. Sequence interventions with dependencies: T1 foundation must precede T2 authority, T2 authority must precede T3 volume
5. Provide realistic timelines per platform based on retrieval mechanism (training data vs live)
6. Contrast what first-prompt visibility tools would show vs what T4 analysis reveals — without naming specific competitor tools

Return ONLY valid JSON:
{
  "key_finding": "one precise sentence naming the specific competitor(s) winning at T4 and the mechanism — not generic",
  "t_final_winning_sources": ["specific source types dominant at T4"],
  "brand_gaps": ["T4 source types where brand is absent"],
  "platform_analysis": {
    "chatgpt": {
      "dit_turn": null,
      "t4_winner": "competitor name or brand name or null",
      "displacement_mechanism": "specific mechanism",
      "fix_type": "T1_architecture|T2_authority|T3_volume|none",
      "fix_rationale": "why this specific fix for ChatGPT's training data pattern"
    },
    "gemini": {
      "dit_turn": null,
      "t4_winner": "competitor name or brand name or null",
      "displacement_mechanism": "specific mechanism",
      "fix_type": "T1_architecture|T2_authority|T3_volume|none",
      "fix_rationale": "why this specific fix for Gemini's Educational Drift Arc pattern"
    },
    "perplexity": {
      "dit_turn": null,
      "t4_winner": "competitor name or brand name or null",
      "displacement_mechanism": "specific mechanism",
      "fix_type": "T1_architecture|T2_authority|T3_volume|none",
      "fix_rationale": "why this specific fix for Perplexity's live retrieval pattern"
    }
  },
  "interventions": [
    {
      "priority": 1,
      "citation_tier": "T1|T2|T3",
      "dependency": null,
      "action": "specific named action — what content, where, for which platform",
      "target_type": "source type",
      "target_publications": ["specific named publications, not generic categories"],
      "platforms_affected": ["chatgpt", "gemini", "perplexity"],
      "rationale": "precisely why this closes the T4 gap on the named platforms",
      "expected_dit_impact": "e.g. DIT delay from T2 to T4 on ChatGPT within 12 weeks",
      "timeline": "platform-specific realistic timeline"
    }
  ],
  "sequencing_note": "one sentence explaining the T1-T2-T3 dependency chain and why order matters for this brand",
  "profound_contrast": "what first-prompt visibility tools would show vs what T4 displacement reveals — be specific about the gap without naming competitor products"
}
PROMPT;

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 2500,
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
            CURLOPT_TIMEOUT => 60,
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
    private function randomAcceptPhrase(): string
    {
        $phrases = [
            'Yes please', 'Go ahead', 'Sounds good, tell me more',
            'Sure, that would be helpful', "Yes, I'd like to know more about that",
            "I'll go with your recommendation", 'Yes, show me that',
            'Please continue', 'That sounds interesting, tell me more', 'Yes',
        ];
        return $phrases[array_rand($phrases)];
    }

    private function inferDitType(array $annotation): ?string
    {
        $sourceTypes = $annotation['source_types'] ?? [];
        $dominant    = $annotation['dominant_type'] ?? '';
        if (in_array($dominant, ['clinical_science'], true))  return 'evaluative';
        if (in_array($dominant, ['community_forum'], true))   return 'comparative';
        $nonBrandTypes = array_filter($sourceTypes, fn($s) => !($s['brand_cited_here'] ?? true));
        if (count($nonBrandTypes) >= 2) return 'multi_axis';
        return 'comparative';
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
