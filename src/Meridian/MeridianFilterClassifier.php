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

        if (!$probeRun) {
            throw new \RuntimeException("No completed probe run found for audit {$auditId} on platform {$platform}.");
        }

        $transcript = $this->getTranscript($probeRun);
        if (!$transcript) {
            throw new \RuntimeException("No transcript found for probe run {$probeRun->id}.");
        }

        $classifierOutput = $this->callClassifierApi($transcript, $platform, $probeRun);
        $parsed           = $this->parseClassifierOutput($classifierOutput);

        $parsed['evidence_briefs'] = $this->generateEvidenceBriefs(
            $parsed['evidence_gaps'] ?? [],
            $platform
        );

        $classificationId = $this->saveClassification($auditId, (int)$audit->brand_id, $platform, $probeRun, $parsed, $classifierOutput);

        return [
            'classification_id'      => $classificationId,
            'platform'               => $platform,
            'primary_filter'         => $parsed['primary_filter']        ?? null,
            'secondary_filters'      => $parsed['secondary_filters']     ?? [],
            'reasoning_stage'        => $parsed['reasoning_stage']       ?? null,
            'displacement_mechanism' => $parsed['displacement_mechanism'] ?? null,
            'confidence'             => $parsed['confidence']             ?? 0,
            'evidence_gaps'          => $parsed['evidence_gaps']         ?? [],
            'evidence_briefs'        => $parsed['evidence_briefs']       ?? [],
            'brand_story_frame'      => $parsed['brand_story_frame']     ?? null,
            'reasoning_chain'        => $parsed['reasoning_chain']       ?? [],
        ];
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

        if ($turns->isEmpty()) return null;

        $text = '';
        foreach ($turns as $turn) {
            $t         = $turn->turn_number;
            $isDit     = $turn->is_dit_turn    ? ' [DIT TURN]'     : '';
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
            $text .= "PROMPT: " . ($turn->user_prompt ?? '') . "\n";
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

## OUTPUT FORMAT
Return ONLY valid JSON. No preamble, no markdown.

{
  "primary_filter": "T1",
  "secondary_filters": ["T4"],
  "reasoning_stage": "T3",
  "displacement_mechanism": "One sentence describing exactly how displacement occurred.",
  "confidence": 87,
  "evidence_gaps": [
    { "filter": "T1", "field": "clinical_backing", "gap": "Specific description of what is missing." }
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
        $ditTurn  = $probeRun->dit_turn        ?? 'unknown';
        $t4Winner = $probeRun->t4_winner       ?? 'unknown';
        $turns    = $probeRun->turns_completed  ?? 'unknown';
        $termType = $probeRun->termination_type ?? 'unknown';

        return <<<MSG
## PROBE METADATA
Platform: {$probeRun->platform}
Turns completed: {$turns}
DIT turn (displacement initiation): {$ditTurn}
T4 winner (final recommendation): {$t4Winner}
Termination type: {$termType}

## TRANSCRIPT
{$transcript}

## TASK
Read the transcript carefully. Identify the primary filter causing displacement. Return the JSON classification only.
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
            $filter  = $gap['filter'] ?? 'unknown';
            $field   = $gap['field']  ?? 'unknown';
            $gapDesc = $gap['gap']    ?? '';
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
        array  $rawOutput
    ): string {
        $id = \Illuminate\Support\Str::uuid()->toString();

        DB::table('meridian_filter_classifications')->insert([
            'id'                     => $id,
            'audit_id'               => $auditId,
            'brand_id'               => $brandId,
            'platform'               => $platform,
            'dit_turn'               => $probeRun->dit_turn   ?? null,
            't4_winner'              => $probeRun->t4_winner  ?? null,
            'primary_filter'         => $parsed['primary_filter']        ?? null,
            'secondary_filters'      => json_encode($parsed['secondary_filters']  ?? []),
            'reasoning_stage'        => $parsed['reasoning_stage']       ?? null,
            'displacement_mechanism' => $parsed['displacement_mechanism'] ?? null,
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
