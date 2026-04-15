<?php

namespace App\Meridian;

use PDO;

class MeridianFilterClassifier
{
    private PDO $db;
    private string $anthropicKey;
    private string $model = 'claude-sonnet-4-20250514';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->anthropicKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    public function classify(int $auditId, string $platform): array
    {
        // 1. Load audit + probe run data
        $audit = $this->getAudit($auditId);
        if (!$audit) {
            throw new \RuntimeException("Audit {$auditId} not found.");
        }

        $probeRun = $this->getProbeRun($auditId, $platform);
        if (!$probeRun) {
            throw new \RuntimeException("No probe run found for audit {$auditId} on platform {$platform}.");
        }

        // 2. Get the transcript
        // TODO: swap this line when transcript storage location is confirmed.
        // Currently reads from raw_config->transcript on meridian_probe_runs.
        // If transcript lives in a separate table, replace getTranscript() body only.
        $transcript = $this->getTranscript($probeRun);
        if (!$transcript) {
            throw new \RuntimeException("No transcript found for probe run {$probeRun['id']}.");
        }

        // 3. Call Claude API
        $classifierOutput = $this->callClassifierApi($transcript, $platform, $probeRun);

        // 4. Parse response
        $parsed = $this->parseClassifierOutput($classifierOutput);

        // 5. Generate evidence briefs
        $parsed['evidence_briefs'] = $this->generateEvidenceBriefs(
            $parsed['evidence_gaps'] ?? [],
            $platform,
            $audit
        );

        // 6. Write to database
        $classificationId = $this->saveClassification($auditId, $audit['brand_id'], $platform, $probeRun, $parsed, $classifierOutput);

        return [
            'classification_id'     => $classificationId,
            'platform'              => $platform,
            'primary_filter'        => $parsed['primary_filter'] ?? null,
            'secondary_filters'     => $parsed['secondary_filters'] ?? [],
            'reasoning_stage'       => $parsed['reasoning_stage'] ?? null,
            'displacement_mechanism'=> $parsed['displacement_mechanism'] ?? null,
            'confidence'            => $parsed['confidence'] ?? 0,
            'evidence_gaps'         => $parsed['evidence_gaps'] ?? [],
            'evidence_briefs'       => $parsed['evidence_briefs'] ?? [],
            'brand_story_frame'     => $parsed['brand_story_frame'] ?? null,
            'reasoning_chain'       => $parsed['reasoning_chain'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Data retrieval
    // -------------------------------------------------------------------------

    private function getAudit(int $auditId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, brand_id, agency_id, status, platforms, completed_at
             FROM meridian_audits
             WHERE id = :id"
        );
        $stmt->execute([':id' => $auditId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getProbeRun(int $auditId, string $platform): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, platform, dit_turn, handoff_turn, t4_winner,
                    turns_completed, termination_type, raw_config,
                    started_at, completed_at
             FROM meridian_probe_runs
             WHERE audit_id = :audit_id
               AND platform = :platform
               AND status = 'completed'
             ORDER BY completed_at DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':audit_id' => $auditId,
            ':platform' => $platform,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getTranscript(array $probeRun): ?string
    {
        // TODO: confirm transcript location with Paul / Sandip.
        // Current assumption: stored as JSON in raw_config->transcript
        // as an array of turn objects: [{turn: 1, prompt: "...", response: "..."}, ...]

        $rawConfig = $probeRun['raw_config'];
        if (!$rawConfig) return null;

        $config = is_string($rawConfig) ? json_decode($rawConfig, true) : $rawConfig;

        if (!empty($config['transcript'])) {
            $turns = $config['transcript'];
            $text = '';
            foreach ($turns as $turn) {
                $t = $turn['turn'] ?? '?';
                $text .= "=== TURN {$t} ===\n";
                $text .= "PROMPT: " . ($turn['prompt'] ?? '') . "\n";
                $text .= "RESPONSE: " . ($turn['response'] ?? '') . "\n\n";
            }
            return trim($text);
        }

        // Fallback: if raw_config itself is a flat transcript string
        if (isset($config['raw_transcript'])) {
            return $config['raw_transcript'];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Claude API call
    // -------------------------------------------------------------------------

    private function callClassifierApi(string $transcript, string $platform, array $probeRun): array
    {
        $systemPrompt = $this->buildSystemPrompt($platform);
        $userMessage  = $this->buildUserMessage($transcript, $probeRun);

        $payload = [
            'model'      => $this->model,
            'max_tokens' => 2000,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage]
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
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
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
        $fingerprint = $this->getPlatformFingerprint($platform);

        return <<<PROMPT
You are the AIVO Evidentia Filter Classifier. Your job is to read a CODA probe transcript (T1–T4 turns) and identify exactly why a brand is being displaced at the purchase decision stage.

## EVIDENTIA FILTER TAXONOMY

**T0 — Entity Recognition Failure**
The model does not recognise the brand as a valid entity. Brand is absent from all turns. Failure mode: No Wikipedia/Wikidata anchor, no training data entity.

**T1 — Clinical Evidence Binary**
The model evaluates whether the brand has peer-reviewed clinical backing for its active ingredients. Failure mode: Competitor has clinical citations; brand does not. Operating stage: T3 (optimisation turn). Primary risk: Gemini.

**T2 — Multi-Axis Lifestyle Fit**
The model evaluates fit across multiple lifestyle and value dimensions simultaneously (sustainability, ethics, accessibility, values alignment). Failure mode: Competitor wins on more axes. Operating stage: T2–T3. Primary risk: ChatGPT.

**T3 — Immediacy & Recency**
The model checks for recent, retrievable, dated evidence. Failure mode: Brand evidence is stale or not live-retrievable. Operating stage: T2–T3. Primary risk: Perplexity.

**T4 — Technology Era Alignment**
The model checks whether the brand's technology/formulation is current. Failure mode: Competitor positioned as more advanced. Operating stage: T4.

**T5 — Price-Value Justification**
The model evaluates whether the price is justified by evidence. Failure mode: No clinical or third-party justification for premium. Operating stage: T3–T4.

**T6 — Context Window Fit**
The model interprets the query context (occasion, skin type, concern) and ranks brands on fit. Failure mode: Competitor framed as better contextual fit. Operating stage: T2.

**T7 — Availability & Accessibility**
The model checks whether the brand is available in the user's context (region, channel, budget). Failure mode: Competitor flagged as more accessible. Operating stage: T1–T2.

**T8 — Regulatory & Safety Signal**
The model evaluates safety credentials, regulatory approvals, dermatologist endorsement. Failure mode: Competitor has stronger safety anchoring. Operating stage: T3.

## PLATFORM FINGERPRINT
Platform: {$platform}
Fingerprint: {$fingerprint['name']}
Primary risk filters: {$fingerprint['primary_risk']}
Evidence priority: {$fingerprint['evidence_priority']}

## REASONING STAGE MAP
- T1 turn: Model establishing entity recognition and category placement
- T2 turn: Model identifying alternatives and evaluation criteria
- T3 turn: Model applying optimisation filter — who meets the criteria?
- T4 turn: Model making final purchase recommendation

## OUTPUT FORMAT
Return ONLY valid JSON. No preamble, no markdown, no explanation outside the JSON object.

{
  "primary_filter": "T1",
  "secondary_filters": ["T4"],
  "reasoning_stage": "T3",
  "displacement_mechanism": "One sentence describing exactly how displacement occurred.",
  "confidence": 87,
  "evidence_gaps": [
    {
      "filter": "T1",
      "field": "clinical_backing",
      "gap": "Specific description of what is missing and why it matters."
    }
  ],
  "brand_story_frame": "clinical_authority | lifestyle_fit | heritage | botanical | technology | accessibility",
  "reasoning_chain": [
    { "step": 1, "question": "What category does the model place this brand in?", "signal": "What the transcript shows." },
    { "step": 2, "question": "What optimisation criterion did the model apply?", "signal": "What the transcript shows." },
    { "step": 3, "question": "Did the brand meet that criterion?", "signal": "What the transcript shows." },
    { "step": 4, "question": "Which competitor met it instead?", "signal": "What the transcript shows." }
  ]
}
PROMPT;
    }

    private function buildUserMessage(string $transcript, array $probeRun): string
    {
        $ditTurn  = $probeRun['dit_turn']  ?? 'unknown';
        $t4Winner = $probeRun['t4_winner'] ?? 'unknown';
        $turns    = $probeRun['turns_completed'] ?? 'unknown';
        $termType = $probeRun['termination_type'] ?? 'unknown';

        return <<<MSG
## PROBE METADATA
Platform: {$probeRun['platform']}
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
            'chatgpt' => [
                'name'               => 'probabilistic_decision_boundary',
                'primary_risk'       => 'T2 multi-axis, competitive',
                'evidence_priority'  => 'Comparative differentiation, tipping evidence',
            ],
            'gemini' => [
                'name'               => 'deterministic_educational_drift',
                'primary_risk'       => 'T1 clinical, T4 technology',
                'evidence_priority'  => 'Peer-reviewed, dense citations, academic vocabulary',
            ],
            'perplexity' => [
                'name'               => 'live_rag_retrieval_recovery',
                'primary_risk'       => 'T7 availability, T6 context',
                'evidence_priority'  => 'Current dated, live URLs, commerce hooks',
            ],
        ];

        return $fingerprints[$platform] ?? [
            'name'              => 'unknown',
            'primary_risk'      => 'unknown',
            'evidence_priority' => 'unknown',
        ];
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    private function parseClassifierOutput(array $apiResponse): array
    {
        $text = $apiResponse['content'][0]['text'] ?? '';

        // Strip any accidental markdown fences
        $text = preg_replace('/```json|```/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse classifier JSON: " . json_last_error_msg() . " | Raw: {$text}");
        }

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Evidence brief generator
    // -------------------------------------------------------------------------

    private function generateEvidenceBriefs(array $gaps, string $platform, array $audit): array
    {
        $briefs = [];
        $platformLabel = ucfirst($platform);

        foreach ($gaps as $gap) {
            $filter = $gap['filter'] ?? 'unknown';
            $field  = $gap['field']  ?? 'unknown';
            $gapDesc = $gap['gap']   ?? '';

            $briefs[] = [
                'filter'   => $filter,
                'field'    => $field,
                'platform' => $platform,
                'brief'    => $this->buildBriefText($filter, $field, $gapDesc, $platformLabel),
            ];
        }

        return $briefs;
    }

    private function buildBriefText(string $filter, string $field, string $gapDesc, string $platform): string
    {
        $filterLabels = [
            'T0' => 'Entity Recognition',
            'T1' => 'Clinical Evidence Binary',
            'T2' => 'Multi-Axis Lifestyle Fit',
            'T3' => 'Immediacy & Recency',
            'T4' => 'Technology Era Alignment',
            'T5' => 'Price-Value Justification',
            'T6' => 'Context Window Fit',
            'T7' => 'Availability & Accessibility',
            'T8' => 'Regulatory & Safety Signal',
        ];

        $filterLabel = $filterLabels[$filter] ?? $filter;

        $requirements = $this->getEvidenceRequirements($filter);

        return "EVIDENCE BRIEF — Filter {$filter}: {$filterLabel} ({$platform})\n\n"
             . "{$gapDesc}\n\n"
             . "To address this gap we need:\n{$requirements}";
    }

    private function getEvidenceRequirements(string $filter): string
    {
        $requirements = [
            'T0' => "(1) Brand Wikipedia page URL or Wikidata QID.\n(2) Canonical brand name exactly as it should appear in model outputs.\n(3) Category and subcategory the brand belongs to.",
            'T1' => "(1) Named active ingredients by INCI or clinical name.\n(2) At least one peer-reviewed study, dermatologist citation, clinical trial, or regulatory approval per ingredient — with the URL or DOI.\n(3) Your honest positioning type: clinical, botanical, heritage, or lifestyle.",
            'T2' => "(1) Sustainability credentials with third-party certification URL.\n(2) Ethics and values positioning with evidence (B-Corp, certifications, named programmes).\n(3) Accessibility context — price positioning relative to category, where the brand is sold.",
            'T3' => "(1) Dated evidence from the last 12 months — press coverage, product launches, clinical updates.\n(2) Live, crawlable URLs only — no PDFs behind logins.\n(3) Commerce hooks — where to buy, current availability.",
            'T4' => "(1) Named formulation technology with launch date.\n(2) Evidence the technology is current — patents, clinical publications, independent coverage.\n(3) Comparison framing versus the competitor technology named in the diagnostic.",
            'T5' => "(1) Price justification evidence — clinical outcomes, ingredient cost, manufacturing process.\n(2) Third-party validation of value — awards, press, expert endorsement.\n(3) Comparative value framing versus the category benchmark.",
            'T6' => "(1) Named use-case or occasion the brand is best suited to.\n(2) Skin type or concern specificity — with supporting evidence.\n(3) Expert or clinical recommendation for this specific context.",
            'T7' => "(1) Retail channel list — where available online and in-store, with URLs.\n(2) Regional availability — specific countries or regions.\n(3) Budget positioning — how the brand is priced relative to accessible alternatives.",
            'T8' => "(1) Dermatologist or clinician endorsement with name and credential.\n(2) Regulatory approval or certification — FDA, EMA, MHRA, or equivalent.\n(3) Safety or allergy testing certification with URL.",
        ];

        return $requirements[$filter] ?? "(1) Provide independently verifiable evidence for this filter type.\n(2) Include source URLs or DOIs for all claims.";
    }

    // -------------------------------------------------------------------------
    // Database write
    // -------------------------------------------------------------------------

    private function saveClassification(
        int    $auditId,
        int    $brandId,
        string $platform,
        array  $probeRun,
        array  $parsed,
        array  $rawOutput
    ): string {
        $stmt = $this->db->prepare(
            "INSERT INTO meridian_filter_classifications
                (audit_id, brand_id, platform, dit_turn, t4_winner,
                 primary_filter, secondary_filters, reasoning_stage,
                 displacement_mechanism, evidence_gaps, evidence_briefs,
                 brand_story_frame, reasoning_chain, classifier_output, confidence_score)
             VALUES
                (:audit_id, :brand_id, :platform, :dit_turn, :t4_winner,
                 :primary_filter, :secondary_filters, :reasoning_stage,
                 :displacement_mechanism, :evidence_gaps, :evidence_briefs,
                 :brand_story_frame, :reasoning_chain, :classifier_output, :confidence_score)
             RETURNING id"
        );

        $stmt->execute([
            ':audit_id'               => $auditId,
            ':brand_id'               => $brandId,
            ':platform'               => $platform,
            ':dit_turn'               => $probeRun['dit_turn'],
            ':t4_winner'              => $probeRun['t4_winner'],
            ':primary_filter'         => $parsed['primary_filter'] ?? null,
            ':secondary_filters'      => json_encode($parsed['secondary_filters'] ?? []),
            ':reasoning_stage'        => $parsed['reasoning_stage'] ?? null,
            ':displacement_mechanism' => $parsed['displacement_mechanism'] ?? null,
            ':evidence_gaps'          => json_encode($parsed['evidence_gaps'] ?? []),
            ':evidence_briefs'        => json_encode($parsed['evidence_briefs'] ?? []),
            ':brand_story_frame'      => $parsed['brand_story_frame'] ?? null,
            ':reasoning_chain'        => json_encode($parsed['reasoning_chain'] ?? []),
            ':classifier_output'      => json_encode($rawOutput),
            ':confidence_score'       => $parsed['confidence'] ?? 0,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['id'];
    }
}
