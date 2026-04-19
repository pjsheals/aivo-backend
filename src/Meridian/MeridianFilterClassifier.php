<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

class MeridianFilterClassifier
{
    private string $anthropicKey;
    private string $model = 'claude-sonnet-4-20250514';

    public function __construct()
    {
        $this->anthropicKey = env('ANTHROPIC_API_KEY') ?: '';
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    public function classify(int $auditId, string $platform): array
    {
        $audit = DB::table('meridian_audits')->find($auditId);
        if (!$audit) {
            throw new \RuntimeException("Audit {$auditId} not found.");
        }

        $probeRun = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->where('platform', $platform)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        // Fallback: if no completed probe run, accept any status.
        // The worker may not mark probe runs as completed even when the
        // audit itself is done — the audit completion is the authority.
        if (!$probeRun) {
            $probeRun = DB::table('meridian_probe_runs')
                ->where('audit_id', $auditId)
                ->where('platform', $platform)
                ->orderByDesc('updated_at')
                ->first();
        }

        if (!$probeRun) {
            // No probe run in meridian_probe_runs — fall back to journey_runs JSON
            // stored in meridian_brand_audit_results. This happens when the audit
            // runner writes results as a JSON blob rather than individual probe rows.
            return $this->buildJourneyRunsClassification($auditId, (int)$audit->brand_id, $platform);
        }

        // Derive probe type from instrument and probe_mode
        $probeType = $this->deriveProbeType($probeRun);

        // Calculate survival gap — how many turns between displacement and handoff
        $survivalGap = null;
        if ($probeRun->handoff_turn !== null && $probeRun->dit_turn !== null) {
            $survivalGap = (int)$probeRun->handoff_turn - (int)$probeRun->dit_turn;
        }

        $transcript = $this->getTranscript($probeRun);

        // ── Fallback: no transcript ───────────────────────────────────────────
        // meridian_probe_turns may be empty if the audit runner doesn't persist
        // individual turns. Check if the probe run has meaningful metadata
        // (dit_turn, t4_winner set by the worker). If it does, use that.
        // If not (e.g. probe run is still queued with null fields), fall back
        // to the journey_runs JSON blob in meridian_brand_audit_results which
        // is where the worker stores the structured results summary.
        if (!$transcript) {
            $hasMetadata = ($probeRun->dit_turn !== null || $probeRun->t4_winner !== null
                         || $probeRun->handoff_turn !== null);
            if ($hasMetadata) {
                return $this->buildMetadataClassification(
                    $auditId, (int)$audit->brand_id, $platform,
                    $probeRun, $probeType, $survivalGap
                );
            }
            // Probe run exists but has no useful data — fall back to journey_runs
            return $this->buildJourneyRunsClassification($auditId, (int)$audit->brand_id, $platform);
        }

        $classifierOutput = $this->callClassifierApi($transcript, $platform, $probeRun);
        $parsed           = $this->parseClassifierOutput($classifierOutput);

        $parsed['evidence_briefs'] = $this->generateEvidenceBriefs(
            $parsed['evidence_gaps'] ?? [],
            $platform
        );

        // Store probe_type and survival_gap into parsed for downstream use
        $parsed['probe_type']    = $probeType;
        $parsed['survival_gap']  = $survivalGap;
        $parsed['handoff_turn']  = $probeRun->handoff_turn ? (int)$probeRun->handoff_turn : null;

        // Update probe_run record with probe_type and displacement_criteria
        DB::table('meridian_probe_runs')
            ->where('id', $probeRun->id)
            ->update([
                'probe_type'            => $probeType,
                'displacement_criteria' => $parsed['displacement_criteria'] ?? null,
                'updated_at'            => now(),
            ]);

        $classificationId = $this->saveClassification(
            $auditId,
            (int)$audit->brand_id,
            $platform,
            $probeRun,
            $parsed,
            $classifierOutput,
            $probeType,
            $survivalGap
        );

        return [
            'classification_id'      => $classificationId,
            'platform'               => $platform,
            'probe_type'             => $probeType,
            'primary_filter'         => $parsed['primary_filter']        ?? null,
            'secondary_filters'      => $parsed['secondary_filters']     ?? [],
            'reasoning_stage'        => $parsed['reasoning_stage']       ?? null,
            'displacement_mechanism' => $parsed['displacement_mechanism'] ?? null,
            'displacement_criteria'  => $parsed['displacement_criteria'] ?? null,
            'displacement_turn'      => $probeRun->dit_turn     ? (int)$probeRun->dit_turn     : null,
            'handoff_turn'           => $probeRun->handoff_turn ? (int)$probeRun->handoff_turn : null,
            'survival_gap'           => $survivalGap,
            'displacing_brand'       => $probeRun->t4_winner ?? null,
            'confidence'             => $parsed['confidence']             ?? 0,
            'evidence_gaps'          => $parsed['evidence_gaps']         ?? [],
            'evidence_briefs'        => $parsed['evidence_briefs']       ?? [],
            'brand_story_frame'      => $parsed['brand_story_frame']     ?? null,
            'reasoning_chain'        => $parsed['reasoning_chain']       ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Metadata-only fallback classification (no turn transcript available)
    // -------------------------------------------------------------------------

    /**
     * When meridian_probe_turns has no data for this probe run, derive a
     * classification from the probe run's stored scalar fields. This unblocks
     * the Evidence Portal without requiring a Claude API call.
     */
    private function buildMetadataClassification(
        int    $auditId,
        int    $brandId,
        string $platform,
        object $probeRun,
        string $probeType,
        ?int   $survivalGap
    ): array {
        $ditTurn    = $probeRun->dit_turn    ? (int)$probeRun->dit_turn    : null;
        $t4Winner   = $probeRun->t4_winner   ?? null;
        $rawConfig  = json_decode($probeRun->raw_config ?? '{}', true);

        // Infer primary filter from platform fingerprint and DIT timing
        $primaryFilter = $this->inferPrimaryFilter($platform, $ditTurn);

        // Build minimal evidence gap from what we know
        $evidenceGaps = [];
        if ($ditTurn !== null && $ditTurn <= 2) {
            $evidenceGaps[] = [
                'filter'              => $primaryFilter,
                'field'               => 'transcript_unavailable',
                'gap'                 => "Brand displaced at T{$ditTurn} by " . ($t4Winner ?? 'unknown competitor') .
                                         ". Full turn-level transcript not available — re-run M1 classification after a fresh audit to get detailed gap analysis.",
                'probe_type_relevance'=> $probeType,
            ];
        } else {
            $evidenceGaps[] = [
                'filter'              => $primaryFilter,
                'field'               => 'transcript_unavailable',
                'gap'                 => "Probe run completed but turn-level transcript is not stored. Re-run M1 classification after a fresh full-suite audit to generate detailed evidence gaps.",
                'probe_type_relevance'=> $probeType,
            ];
        }

        $evidenceBriefs = $this->generateEvidenceBriefs($evidenceGaps, $platform);

        $mechanism = $ditTurn !== null
            ? "Brand displaced at turn {$ditTurn}" . ($t4Winner ? " — {$t4Winner} captured the handoff" : "")
            : "Displacement turn not recorded";

        $parsed = [
            'primary_filter'         => $primaryFilter,
            'secondary_filters'      => [],
            'reasoning_stage'        => $ditTurn ? "T{$ditTurn}" : null,
            'displacement_mechanism' => $mechanism,
            'displacement_criteria'  => "Full classification requires turn-level transcript. Re-run audit to populate.",
            'confidence'             => 0,
            'evidence_gaps'          => $evidenceGaps,
            'evidence_briefs'        => $evidenceBriefs,
            'brand_story_frame'      => null,
            'reasoning_chain'        => [],
            'probe_type'             => $probeType,
            'survival_gap'           => $survivalGap,
            'handoff_turn'           => $probeRun->handoff_turn ? (int)$probeRun->handoff_turn : null,
        ];

        // Update probe_run with probe_type
        DB::table('meridian_probe_runs')
            ->where('id', $probeRun->id)
            ->update(['probe_type' => $probeType, 'updated_at' => now()]);

        $classificationId = $this->saveClassification(
            $auditId, $brandId, $platform, $probeRun,
            $parsed, ['metadata_only' => true], $probeType, $survivalGap
        );

        return [
            'classification_id'      => $classificationId,
            'platform'               => $platform,
            'probe_type'             => $probeType,
            'primary_filter'         => $parsed['primary_filter'],
            'secondary_filters'      => [],
            'reasoning_stage'        => $parsed['reasoning_stage'],
            'displacement_mechanism' => $parsed['displacement_mechanism'],
            'displacement_criteria'  => $parsed['displacement_criteria'],
            'displacement_turn'      => $ditTurn,
            'handoff_turn'           => $probeRun->handoff_turn ? (int)$probeRun->handoff_turn : null,
            'survival_gap'           => $survivalGap,
            'displacing_brand'       => $t4Winner,
            'confidence'             => 0,
            'evidence_gaps'          => $evidenceGaps,
            'evidence_briefs'        => $evidenceBriefs,
            'brand_story_frame'      => null,
            'reasoning_chain'        => [],
            'metadata_only'          => true,
        ];
    }

    /**
     * Infer the most likely primary filter from platform fingerprint and DIT timing.
     */
    private function inferPrimaryFilter(string $platform, ?int $ditTurn): string
    {
        if ($ditTurn === null) return 'T2';
        if ($ditTurn <= 1) return 'T0'; // Very early = entity recognition failure
        if ($platform === 'gemini') return 'T1'; // Gemini = clinical evidence binary
        if ($platform === 'perplexity') return 'T3'; // Perplexity = recency/retrieval
        return 'T2'; // ChatGPT default = multi-axis lifestyle fit
    }

    // -------------------------------------------------------------------------
    // Derive probe type from probe run
    // -------------------------------------------------------------------------


    /**
     * Fallback when meridian_probe_runs has no rows for this audit.
     * Reads the journey_runs JSON blob from meridian_brand_audit_results
     * and synthesises a classification from whatever platform data exists.
     */
    private function buildJourneyRunsClassification(int $auditId, int $brandId, string $platform): array
    {
        $resultRow = DB::table('meridian_brand_audit_results')
            ->where('audit_id', $auditId)
            ->first();

        $journeyRuns = [];
        if ($resultRow && !empty($resultRow->journey_runs)) {
            $allRuns = json_decode($resultRow->journey_runs, true) ?? [];
            foreach ($allRuns as $key => $run) {
                $runPlatform = is_string($key) ? $key : ($run['platform'] ?? '');
                if (strtolower($runPlatform) === $platform) {
                    $journeyRuns = $run;
                    break;
                }
            }
        }

        // journey_runs is stored by the worker with camelCase keys.
        // Support both camelCase (worker output) and snake_case (future normalised format).
        $ditTurn  = isset($journeyRuns['ditTurn'])     ? (int)$journeyRuns['ditTurn']
                  : (isset($journeyRuns['dit_turn'])   ? (int)$journeyRuns['dit_turn']   : null);
        $handoff  = isset($journeyRuns['handoffTurn'])  ? (int)$journeyRuns['handoffTurn']
                  : (isset($journeyRuns['handoff_turn'])? (int)$journeyRuns['handoff_turn']: null);
        $t4Winner = $journeyRuns['displacingBrand'] ?? $journeyRuns['t4_winner'] ?? null;
        $probeMode = $journeyRuns['probeMode'] ?? $journeyRuns['probe_mode'] ?? 'anchored';
        $turns     = $journeyRuns['totalTurns'] ?? $journeyRuns['turns'] ?? null;
        $termType  = $journeyRuns['terminationType'] ?? $journeyRuns['termination'] ?? null;

        $survivalGap = ($ditTurn !== null && $handoff !== null) ? $handoff - $ditTurn : null;

        $probeType = ($probeMode === 'generic') ? 'spontaneous_consideration' : 'decision_stage';

        $syntheticRun = (object)[
            'id'               => 0,
            'platform'         => $platform,
            'probe_mode'       => $probeMode,
            'instrument'       => $journeyRuns['instrument'] ?? 'BJP-D',
            'dit_turn'         => $ditTurn,
            'handoff_turn'     => $handoff,
            't4_winner'        => $t4Winner,
            'turns_completed'  => $turns,
            'termination_type' => $term,
        ];

        // If we have journey_runs data with DIT info, build a synthetic transcript
        // and run the full Claude classifier — this gives real gap analysis rather
        // than placeholder text.
        if (!empty($journeyRuns) && $ditTurn !== null) {
            $syntheticTranscript = $this->buildJourneyRunsTranscript($journeyRuns, $platform);
            try {
                $classifierOutput = $this->callClassifierApi($syntheticTranscript, $platform, $syntheticRun);
                $parsed           = $this->parseClassifierOutput($classifierOutput);
                $parsed['evidence_briefs'] = $this->generateEvidenceBriefs($parsed['evidence_gaps'] ?? [], $platform);
                $parsed['probe_type']   = $probeType;
                $parsed['survival_gap'] = $survivalGap;
                $parsed['handoff_turn'] = $handoff;

                $classificationId = $this->saveClassification(
                    $auditId, $brandId, $platform, $syntheticRun,
                    $parsed, $classifierOutput, $probeType, $survivalGap
                );

                return [
                    'classification_id'      => $classificationId,
                    'platform'               => $platform,
                    'probe_type'             => $probeType,
                    'primary_filter'         => $parsed['primary_filter']        ?? null,
                    'secondary_filters'      => $parsed['secondary_filters']     ?? [],
                    'reasoning_stage'        => $parsed['reasoning_stage']       ?? null,
                    'displacement_mechanism' => $parsed['displacement_mechanism'] ?? null,
                    'displacement_criteria'  => $parsed['displacement_criteria'] ?? null,
                    'displacement_turn'      => $ditTurn,
                    'handoff_turn'           => $handoff,
                    'survival_gap'           => $survivalGap,
                    'displacing_brand'       => $t4Winner,
                    'confidence'             => $parsed['confidence']            ?? 0,
                    'evidence_gaps'          => $parsed['evidence_gaps']         ?? [],
                    'evidence_briefs'        => $parsed['evidence_briefs']       ?? [],
                    'brand_story_frame'      => $parsed['brand_story_frame']     ?? null,
                    'reasoning_chain'        => $parsed['reasoning_chain']       ?? [],
                ];
            } catch (\Throwable $e) {
                log_error('[MeridianFilterClassifier] journey_runs Claude call failed', ['error' => $e->getMessage()]);
                // Fall through to metadata-only classification below
            }
        }

        // Final fallback — no journey_runs data or Claude failed
        $primaryFilter = $this->inferPrimaryFilter($platform, $ditTurn);
        $mechanism = $ditTurn !== null
            ? "Brand displaced at turn {$ditTurn}" . ($t4Winner ? " — {$t4Winner} captured the handoff" : "")
            : "Displacement turn not recorded";

        $evidenceGaps = [[
            'filter'               => $primaryFilter,
            'field'                => 'probe_run_unavailable',
            'gap'                  => "Probe run data sourced from journey_runs summary. " .
                                      "DIT: " . ($ditTurn !== null ? "T{$ditTurn}" : "not recorded") . ". " .
                                      "T4 winner: " . ($t4Winner ?? "none") . ". " .
                                      "Re-run a fresh full-suite audit to generate full transcript-level classification.",
            'probe_type_relevance' => $probeType,
        ]];

        $evidenceBriefs = $this->generateEvidenceBriefs($evidenceGaps, $platform);

        $parsed = [
            'primary_filter'         => $primaryFilter,
            'secondary_filters'      => [],
            'reasoning_stage'        => $ditTurn ? "T{$ditTurn}" : null,
            'displacement_mechanism' => $mechanism,
            'displacement_criteria'  => "Full classification requires probe run rows. Re-run a fresh audit to populate.",
            'confidence'             => 0,
            'evidence_gaps'          => $evidenceGaps,
            'evidence_briefs'        => $evidenceBriefs,
            'brand_story_frame'      => null,
            'reasoning_chain'        => [],
            'probe_type'             => $probeType,
            'survival_gap'           => $survivalGap,
            'handoff_turn'           => $handoff,
        ];

        $classificationId = $this->saveClassification(
            $auditId, $brandId, $platform, $syntheticRun,
            $parsed, ['journey_runs_fallback' => true], $probeType, $survivalGap
        );

        return [
            'classification_id'      => $classificationId,
            'platform'               => $platform,
            'probe_type'             => $probeType,
            'primary_filter'         => $primaryFilter,
            'secondary_filters'      => [],
            'reasoning_stage'        => $parsed['reasoning_stage'],
            'displacement_mechanism' => $mechanism,
            'displacement_criteria'  => $parsed['displacement_criteria'],
            'displacement_turn'      => $ditTurn,
            'handoff_turn'           => $handoff,
            'survival_gap'           => $survivalGap,
            'displacing_brand'       => $t4Winner,
            'confidence'             => 0,
            'evidence_gaps'          => $evidenceGaps,
            'evidence_briefs'        => $evidenceBriefs,
            'brand_story_frame'      => null,
            'reasoning_chain'        => [],
            'journey_runs_fallback'  => true,
        ];
    }

    /**
     * Build a synthetic transcript from journey_runs summary data.
     * Used when meridian_probe_turns is empty but journey_runs JSON has
     * structured probe result data from the audit worker.
     */
    private function buildJourneyRunsTranscript(array $journeyRuns, string $platform): string
    {
        $lines = ["SYNTHETIC TRANSCRIPT FROM JOURNEY RUNS SUMMARY"];
        $lines[] = "Platform: {$platform}";
        $lines[] = "Note: Turn-level transcript unavailable. Classification based on probe run summary data.";
        $lines[] = "";

        // Support both camelCase (worker output) and snake_case keys
        $ditTurn   = $journeyRuns['ditTurn']       ?? $journeyRuns['dit_turn']    ?? null;
        $handoff   = $journeyRuns['handoffTurn']   ?? $journeyRuns['handoff_turn'] ?? null;
        $t4Winner  = $journeyRuns['displacingBrand']?? $journeyRuns['t4_winner']  ?? null;
        $turns     = $journeyRuns['totalTurns']    ?? $journeyRuns['turns']        ?? null;
        $probeMode = $journeyRuns['probeMode']     ?? $journeyRuns['probe_mode']   ?? 'anchored';
        $term      = $journeyRuns['terminationType']?? $journeyRuns['termination'] ?? null;
        $score     = $journeyRuns['score']         ?? null;

        $lines[] = "=== PROBE RUN SUMMARY ===";
        $lines[] = "Probe mode: {$probeMode}";
        if ($turns)    $lines[] = "Turns completed: {$turns}";
        if ($ditTurn)  $lines[] = "DIT (displacement initiation turn): T{$ditTurn} [DIT TURN]";
        if ($handoff)  $lines[] = "Commercial handoff turn: T{$handoff} [HANDOFF TURN]";
        if ($t4Winner) $lines[] = "T4 winner (brand capturing handoff): {$t4Winner}";
        if ($term)     $lines[] = "Termination type: {$term}";
        if ($score !== null) $lines[] = "Probe score: {$score}";

        // Include any additional structured data from the run
        $exclude = ['dit_turn','handoff_turn','t4_winner','turns','probe_mode','termination','score','platform'];
        foreach ($journeyRuns as $key => $val) {
            if (!in_array($key, $exclude, true) && !is_array($val) && $val !== null && $val !== '') {
                $lines[] = ucfirst(str_replace('_', ' ', $key)) . ": {$val}";
            }
        }

        $lines[] = "";
        $lines[] = "=== DISPLACEMENT CONTEXT ===";
        if ($ditTurn !== null && $t4Winner !== null) {
            $lines[] = "Brand held primary status until turn {$ditTurn}, at which point {$t4Winner} was recommended instead.";
            if ($handoff !== null) {
                $gap = $handoff - $ditTurn;
                $lines[] = "The commercial handoff occurred at turn {$handoff} ({$gap} turns of revenue exposure after displacement).";
            }
        } elseif ($ditTurn !== null) {
            $lines[] = "Displacement initiated at turn {$ditTurn}. Brand absent from commercial handoff.";
        } else {
            $lines[] = "No displacement recorded — brand held primary status throughout or was absent from start.";
        }

        return implode("
", $lines);
    }

    private function deriveProbeType(object $probeRun): string
    {
        $instrument = strtolower($probeRun->instrument ?? '');
        $probeMode  = strtolower($probeRun->probe_mode ?? '');

        if (str_contains($instrument, 'psos')) {
            return 'awareness';
        }
        if ($probeMode === 'anchored') {
            return 'decision_stage';
        }
        if ($probeMode === 'generic') {
            return 'spontaneous_consideration';
        }
        // Default — undirected agentic probes without brand name = spontaneous
        if (str_contains($instrument, 'undirected') || str_contains($instrument, 'u ')) {
            return 'spontaneous_consideration';
        }
        return 'decision_stage';
    }

    // -------------------------------------------------------------------------
    // Transcript
    // -------------------------------------------------------------------------

    private function getTranscript(object $probeRun): ?string
    {
        $turns = DB::table('meridian_probe_turns')
            ->where('probe_run_id', $probeRun->id)
            ->orderBy('turn_number')
            ->get();

        if ($turns->isEmpty()) {
            // Try to build a minimal transcript from raw_result if stored
            $rawResult = json_decode($probeRun->raw_result ?? 'null', true);
            if (!empty($rawResult) && is_array($rawResult)) {
                return "PARTIAL TRANSCRIPT FROM RAW RESULT (turn table empty)\n" .
                       json_encode($rawResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            return null;
        }

        $text = '';
        foreach ($turns as $turn) {
            $t         = $turn->turn_number;
            $isDit     = $turn->is_dit_turn     ? ' [DIT TURN]'     : '';
            $isHandoff = $turn->is_handoff_turn ? ' [HANDOFF TURN]' : '';
            $presence  = $turn->brand_presence  ? ' [Brand: ' . $turn->brand_presence . ']' : '';

            $citations = '';
            if (!empty($turn->citation_urls)) {
                $citData = json_decode($turn->citation_urls, true);
                $urls    = $citData['urls'] ?? [];
                if (!empty($urls)) {
                    $citations = "\nCITATIONS: " . implode(', ', array_slice($urls, 0, 5));
                }
            }

            $text .= "=== TURN {$t}{$isDit}{$isHandoff}{$presence} ===\n";
            $text .= "PROMPT: " . ($turn->user_prompt  ?? '') . "\n";
            $text .= "RESPONSE: " . ($turn->model_response ?? '');
            $text .= $citations . "\n\n";
        }

        return trim($text);
    }

    // -------------------------------------------------------------------------
    // Claude API call
    // -------------------------------------------------------------------------

    private function callClassifierApi(string $transcript, string $platform, object $probeRun): array
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => 2000,
            'system'     => $this->buildSystemPrompt($platform),
            'messages'   => [
                ['role' => 'user', 'content' => $this->buildUserMessage($transcript, $probeRun)]
            ],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->anthropicKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Claude API error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['content'][0]['text'])) {
            throw new \RuntimeException("Unexpected Claude API response structure.");
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Prompts
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(string $platform): string
    {
        $fp = $this->getPlatformFingerprint($platform);

        return <<<PROMPT
You are the AIVO Evidentia Filter Classifier. Your job is to read a probe transcript and identify exactly why a brand is being displaced at the purchase decision stage.

## EVIDENTIA FILTER TAXONOMY

**T0 — Entity Recognition Failure**
The model does not recognise the brand as a valid entity. Failure mode: No Wikipedia/Wikidata anchor.

**T1 — Clinical Evidence Binary**
The model evaluates whether the brand has peer-reviewed clinical backing for its active ingredients. Failure mode: Competitor has clinical citations; brand does not. Primary risk: Gemini.

**T2 — Multi-Axis Lifestyle Fit**
The model evaluates fit across multiple lifestyle and value dimensions. Failure mode: Competitor wins on more axes. Primary risk: ChatGPT.

**T3 — Immediacy & Recency**
The model checks for recent, retrievable, dated evidence. Failure mode: Brand evidence is stale. Primary risk: Perplexity.

**T4 — Technology Era Alignment**
The model checks whether the brand's technology/formulation is current. Failure mode: Competitor positioned as more advanced.

**T5 — Price-Value Justification**
The model evaluates whether the price is justified by evidence. Failure mode: No clinical or third-party justification for premium.

**T6 — Context Window Fit**
The model interprets the query context and ranks brands on fit. Failure mode: Competitor framed as better contextual fit.

**T7 — Availability & Accessibility**
The model checks whether the brand is available in the user's context. Failure mode: Competitor flagged as more accessible.

**T8 — Regulatory & Safety Signal**
The model evaluates safety credentials and regulatory approvals. Failure mode: Competitor has stronger safety anchoring.

## PLATFORM FINGERPRINT
Platform: {$platform}
Fingerprint: {$fp['name']}
Primary risk filters: {$fp['primary_risk']}
Evidence priority: {$fp['evidence_priority']}

## DISPLACEMENT CRITERIA
The displacement_criteria field is critical. It must express — in one precise sentence — the specific question or criteria the model applied at the displacement turn that the brand failed to answer. This is not a description of the mechanism. It is the exact question that, if the brand had evidence to answer it, would have kept it in the conversation.

Examples:
- "Which anti-ageing moisturiser has peer-reviewed clinical proof of visible wrinkle reduction within 8 weeks?"
- "Which product has dermatologist-endorsed retinol formulation with documented efficacy for women over 50?"
- "Which brand has the most recent independently verified evidence of its core skincare claim?"

## OUTPUT FORMAT
Return ONLY valid JSON. No preamble, no markdown.

{
  "primary_filter": "T1",
  "secondary_filters": ["T4"],
  "reasoning_stage": "T3",
  "displacement_mechanism": "One sentence describing exactly how displacement occurred.",
  "displacement_criteria": "The specific criteria-based question the model applied at the displacement turn that the brand failed to answer.",
  "confidence": 87,
  "evidence_gaps": [
    {
      "filter": "T1",
      "field": "clinical_backing",
      "gap": "Specific description of what is missing.",
      "probe_type_relevance": "decision_stage"
    }
  ],
  "brand_story_frame": "clinical_authority",
  "reasoning_chain": [
    { "step": 1, "question": "What category does the model place this brand in?", "signal": "What the transcript shows." },
    { "step": 2, "question": "What optimisation criterion did the model apply?", "signal": "What the transcript shows." },
    { "step": 3, "question": "Did the brand meet that criterion?", "signal": "What the transcript shows." },
    { "step": 4, "question": "Which competitor met it instead?", "signal": "What the transcript shows." }
  ]
}
PROMPT;
    }

    private function buildUserMessage(string $transcript, object $probeRun): string
    {
        $ditTurn     = $probeRun->dit_turn        ?? 'null (no displacement)';
        $handoffTurn = $probeRun->handoff_turn    ?? 'unknown';
        $t4Winner    = $probeRun->t4_winner       ?? 'unknown';
        $turns       = $probeRun->turns_completed ?? 'unknown';
        $termType    = $probeRun->termination_type ?? 'unknown';
        $probeMode   = $probeRun->probe_mode       ?? 'unknown';

        $survivalGap = 'N/A';
        if ($probeRun->handoff_turn !== null && $probeRun->dit_turn !== null) {
            $survivalGap = ((int)$probeRun->handoff_turn - (int)$probeRun->dit_turn) . ' turns of revenue exposure';
        }

        return <<<MSG
## PROBE METADATA
Platform: {$probeRun->platform}
Probe mode: {$probeMode}
Turns completed: {$turns}
DIT turn (displacement initiation): {$ditTurn}
Commercial handoff turn: {$handoffTurn}
Survival gap: {$survivalGap}
Brand at handoff / T4 winner: {$t4Winner}
Termination type: {$termType}

## TRANSCRIPT
{$transcript}

## TASK
Read the transcript carefully. Identify:
1. The primary filter causing displacement
2. The exact displacement_criteria — the specific question the model applied at the DIT turn that the brand failed to answer
3. All evidence gaps needed to survive from the DIT turn to the commercial handoff turn

Return the JSON classification only.
MSG;
    }

    // -------------------------------------------------------------------------
    // Platform fingerprints
    // -------------------------------------------------------------------------

    private function getPlatformFingerprint(string $platform): array
    {
        $fingerprints = [
            'chatgpt'    => ['name' => 'probabilistic_decision_boundary',  'primary_risk' => 'T2 multi-axis, competitive',    'evidence_priority' => 'Comparative differentiation, tipping evidence'],
            'gemini'     => ['name' => 'deterministic_educational_drift',   'primary_risk' => 'T1 clinical, T4 technology',    'evidence_priority' => 'Peer-reviewed, dense citations, academic vocabulary'],
            'perplexity' => ['name' => 'live_rag_retrieval_recovery',       'primary_risk' => 'T7 availability, T6 context',   'evidence_priority' => 'Current dated, live URLs, commerce hooks'],
        ];

        return $fingerprints[$platform] ?? ['name' => 'unknown', 'primary_risk' => 'unknown', 'evidence_priority' => 'unknown'];
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    private function parseClassifierOutput(array $apiResponse): array
    {
        $text = $apiResponse['content'][0]['text'] ?? '';
        $text = preg_replace('/```json|```/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse classifier JSON: " . json_last_error_msg());
        }

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Evidence briefs
    // -------------------------------------------------------------------------

    private function generateEvidenceBriefs(array $gaps, string $platform): array
    {
        $briefs = [];
        foreach ($gaps as $gap) {
            $filter  = $gap['filter']  ?? 'unknown';
            $field   = $gap['field']   ?? 'unknown';
            $gapDesc = $gap['gap']     ?? '';
            $briefs[] = [
                'filter'   => $filter,
                'field'    => $field,
                'platform' => $platform,
                'brief'    => $this->buildBriefText($filter, $gapDesc, ucfirst($platform)),
            ];
        }
        return $briefs;
    }

    private function buildBriefText(string $filter, string $gapDesc, string $platform): string
    {
        $labels = [
            'T0' => 'Entity Recognition',        'T1' => 'Clinical Evidence Binary',
            'T2' => 'Multi-Axis Lifestyle Fit',   'T3' => 'Immediacy & Recency',
            'T4' => 'Technology Era Alignment',   'T5' => 'Price-Value Justification',
            'T6' => 'Context Window Fit',          'T7' => 'Availability & Accessibility',
            'T8' => 'Regulatory & Safety Signal',
        ];
        $requirements = [
            'T0' => "(1) Brand Wikipedia page URL or Wikidata QID.\n(2) Canonical brand name.\n(3) Category and subcategory.",
            'T1' => "(1) Named active ingredients by INCI or clinical name.\n(2) Peer-reviewed study, dermatologist citation, or regulatory approval per ingredient — with URL or DOI.\n(3) Positioning type: clinical, botanical, heritage, or lifestyle.",
            'T2' => "(1) Sustainability credentials with third-party certification URL.\n(2) Ethics and values positioning with evidence.\n(3) Accessibility context — price positioning and where sold.",
            'T3' => "(1) Dated evidence from the last 12 months — press, launches, clinical updates.\n(2) Live, crawlable URLs only.\n(3) Commerce hooks — where to buy, current availability.",
            'T4' => "(1) Named formulation technology with launch date.\n(2) Evidence the technology is current — patents, publications, independent coverage.\n(3) Comparison framing versus the competitor technology.",
            'T5' => "(1) Price justification evidence — clinical outcomes, ingredient cost, manufacturing.\n(2) Third-party validation — awards, press, expert endorsement.\n(3) Comparative value framing versus category benchmark.",
            'T6' => "(1) Named use-case or occasion the brand suits best.\n(2) Skin type or concern specificity with evidence.\n(3) Expert or clinical recommendation for this context.",
            'T7' => "(1) Retail channel list — online and in-store, with URLs.\n(2) Regional availability.\n(3) Budget positioning versus accessible alternatives.",
            'T8' => "(1) Dermatologist or clinician endorsement with name and credential.\n(2) Regulatory approval — FDA, EMA, MHRA, or equivalent.\n(3) Safety or allergy testing certification with URL.",
        ];

        $label = $labels[$filter] ?? $filter;
        $reqs  = $requirements[$filter] ?? "(1) Provide independently verifiable evidence.\n(2) Include source URLs or DOIs.";

        return "EVIDENCE BRIEF — Filter {$filter}: {$label} ({$platform})\n\n{$gapDesc}\n\nTo address this gap we need:\n{$reqs}";
    }

    // -------------------------------------------------------------------------
    // Database write
    // -------------------------------------------------------------------------

    private function saveClassification(
        int    $auditId,
        int    $brandId,
        string $platform,
        object $probeRun,
        array  $parsed,
        array  $rawOutput,
        string $probeType,
        ?int   $survivalGap
    ): string {
        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        DB::table('meridian_filter_classifications')->insert([
            'id'                     => $id,
            'audit_id'               => $auditId,
            'brand_id'               => $brandId,
            'platform'               => $platform,
            'probe_type'             => $probeType,
            'dit_turn'               => $probeRun->dit_turn    ?? null,
            'handoff_turn'           => $probeRun->handoff_turn ? (int)$probeRun->handoff_turn : null,
            'survival_gap'           => $survivalGap,
            't4_winner'              => $probeRun->t4_winner   ?? null,
            'primary_filter'         => $parsed['primary_filter']        ?? null,
            'secondary_filters'      => json_encode($parsed['secondary_filters']  ?? []),
            'reasoning_stage'        => $parsed['reasoning_stage']       ?? null,
            'displacement_mechanism' => $parsed['displacement_mechanism'] ?? null,
            'displacement_criteria'  => $parsed['displacement_criteria'] ?? null,
            'evidence_gaps'          => json_encode($parsed['evidence_gaps']       ?? []),
            'evidence_briefs'        => json_encode($parsed['evidence_briefs']     ?? []),
            'brand_story_frame'      => $parsed['brand_story_frame']     ?? null,
            'reasoning_chain'        => json_encode($parsed['reasoning_chain']     ?? []),
            'classifier_output'      => json_encode($rawOutput),
            'confidence_score'       => $parsed['confidence']            ?? 0,
            'created_at'             => now(),
        ]);

        return $id;
    }
}
