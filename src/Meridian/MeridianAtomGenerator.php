<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianAtomGenerator — Module 4
 *
 * Generates MAS 1.1 Micro-Content Atoms from verified evidence submissions.
 * One atom per filter gap per model variant.
 *
 * Stage 3 changes:
 * - Inherits probe_type from M1 classification (awareness / decision_stage / spontaneous_consideration)
 * - All new atoms set to approval_status = 'pending_approval'
 * - Status = 'generated' until explicitly approved via /api/meridian/atoms/approve
 */
class MeridianAtomGenerator
{
    private string $anthropicKey;
    private string $model = 'claude-sonnet-4-20250514';

    private const REASONING_STAGE_MAP = [
        'T1' => ['stage' => 'T1', 'question' => 'Do I know this entity? What category?'],
        'T2' => ['stage' => 'T2', 'question' => 'Who are the alternatives? What are the criteria?'],
        'T3' => ['stage' => 'T3', 'question' => 'Which brand meets the optimisation criteria?'],
        'T4' => ['stage' => 'T4', 'question' => 'Final recommendation — which one?'],
    ];

    private const PLATFORM_FINGERPRINTS = [
        'gemini'     => ['name' => 'deterministic_educational_drift',  'vocab' => 'clinical_academic',   'citation_density' => 'maximum'],
        'chatgpt'    => ['name' => 'probabilistic_decision_boundary',  'vocab' => 'comparative_decisive', 'citation_density' => 'moderate'],
        'perplexity' => ['name' => 'live_rag_retrieval_recovery',      'vocab' => 'current_commerce',     'citation_density' => 'current_only'],
        'universal'  => ['name' => 'universal',                        'vocab' => 'balanced',             'citation_density' => 'balanced'],
    ];

    public function __construct()
    {
        $this->anthropicKey = env('ANTHROPIC_API_KEY') ?: '';
    }

    // -------------------------------------------------------------------------
    // Public entry point — generate atom for one filter + variant
    // -------------------------------------------------------------------------

    public function generate(int $brandId, int $auditId, string $filterType, string $modelVariant): array
    {
        $brand = DB::table('meridian_brands')->find($brandId);
        if (!$brand) throw new \RuntimeException("Brand {$brandId} not found.");

        $audit = DB::table('meridian_audits')->find($auditId);
        if (!$audit) throw new \RuntimeException("Audit {$auditId} not found.");

        // Load verified evidence for this filter
        $evidence = DB::table('meridian_evidence_submissions')
            ->where('brand_id', $brandId)
            ->where('filter_type', $filterType)
            ->where('verification_status', 'verified')
            ->orderByDesc('authority_score')
            ->get();

        if ($evidence->isEmpty()) {
            throw new \RuntimeException("No verified evidence found for filter {$filterType}. Submit and verify evidence first.");
        }

        // Load M1 classification for context + probe_type
        $classificationPlatform = $modelVariant === 'universal' ? 'gemini' : $modelVariant;
        $classification = DB::table('meridian_filter_classifications')
            ->where('audit_id', $auditId)
            ->where('platform', $classificationPlatform)
            ->orderByDesc('created_at')
            ->first();

        // Inherit probe_type from classification — fallback to decision_stage
        $probeType = $classification->probe_type ?? 'decision_stage';

        // Load probe run for DIT/T4 winner context
        $probeRun = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->where('platform', $classificationPlatform)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        // Generate atom via Claude API
        $atomJson = $this->callAtomGenerator($brand, $filterType, $modelVariant, $evidence, $classification, $probeRun);

        // Validate the atom
        $validation = $this->validateAtom($atomJson, $filterType, $modelVariant);

        // Save to database — all new atoms go to pending_approval
        $id = $this->saveAtom(
            $brandId, $auditId, (int)$audit->agency_id,
            $filterType, $modelVariant, $probeType,
            $atomJson, $validation
        );

        return [
            'atom_id'          => $id,
            'filter_type'      => $filterType,
            'model_variant'    => $modelVariant,
            'probe_type'       => $probeType,
            'approval_status'  => 'pending_approval',
            'validation_score' => $validation['score'],
            'validation_notes' => $validation['notes'],
            'status'           => 'generated',
            'atom'             => $atomJson,
        ];
    }

    // -------------------------------------------------------------------------
    // Generate all variants for a filter gap
    // -------------------------------------------------------------------------

    public function generateAllVariants(int $brandId, int $auditId, string $filterType): array
    {
        $results = [];
        $errors  = [];

        foreach (['gemini', 'chatgpt', 'perplexity', 'universal'] as $variant) {
            try {
                $results[$variant] = $this->generate($brandId, $auditId, $filterType, $variant);
            } catch (\Throwable $e) {
                $errors[$variant] = $e->getMessage();
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Claude API call — atom generation
    // -------------------------------------------------------------------------

    private function callAtomGenerator(
        object  $brand,
        string  $filterType,
        string  $modelVariant,
        object  $evidence,
        ?object $classification,
        ?object $probeRun
    ): array {
        $systemPrompt = $this->buildSystemPrompt($filterType, $modelVariant);
        $userMessage  = $this->buildUserMessage($brand, $filterType, $modelVariant, $evidence, $classification, $probeRun);

        $payload = [
            'model'      => $this->model,
            'max_tokens' => 2000,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userMessage]],
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
        $text    = $decoded['content'][0]['text'] ?? '';
        $text    = preg_replace('/```json|```/', '', $text);
        $text    = trim($text);

        $atom = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse atom JSON: " . json_last_error_msg());
        }

        return $atom;
    }

    // -------------------------------------------------------------------------
    // System prompt
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(string $filterType, string $modelVariant): string
    {
        $fp           = self::PLATFORM_FINGERPRINTS[$modelVariant] ?? self::PLATFORM_FINGERPRINTS['universal'];
        $filterLabel  = $this->getFilterLabel($filterType);
        $reasoningMap = $this->getReasoningStage($filterType);

        return <<<PROMPT
You are generating a MAS 1.1 Micro-Content Atom for an AI brand positioning system.

## CONTEXT
Filter type: {$filterType} — {$filterLabel}
Model variant: {$modelVariant}
Platform fingerprint: {$fp['name']}
Vocabulary register: {$fp['vocab']}
Citation density: {$fp['citation_density']}
Reasoning stage target: {$reasoningMap['stage']} — {$reasoningMap['question']}

## TASK
Generate a single MAS 1.1 atom that pre-answers the question the model asks at the reasoning stage above.
The conversational query must be written as the model's internal question at that stage.
The conversational answer must be written in {$fp['vocab']} vocabulary.
Use ONLY the verified evidence provided. Do not fabricate citations or claims.

## OUTPUT FORMAT
Return ONLY valid JSON. No preamble, no markdown, no explanation.

{
  "id": "atom:[brand_slug]:[filter_type]:[model_variant]:v1",
  "mas_version": "1.1",
  "entity": "[canonical brand name]",
  "claim": "[1-2 sentence canonical claim supported by the evidence]",
  "filter_type": "{$filterType}",
  "reasoning_stage": "{$reasoningMap['stage']}",
  "model_variant": "{$modelVariant}",
  "reasoning_fingerprint": "{$fp['name']}",
  "conversational": {
    "query": "[the exact question the model asks at {$reasoningMap['stage']}]",
    "answer": "[brand-specific pre-answer in {$fp['vocab']} vocabulary, 2-4 sentences]"
  },
  "citations": [
    { "type": "peer_reviewed|regulatory|clinical|press|corporate", "title": "...", "url": "...", "doi": "...", "date": "..." }
  ],
  "attributes": {
    "positioning_type": "clinical|botanical|heritage|lifestyle|technology",
    "price_tier": "mass|masstige|prestige|luxury"
  },
  "trust": {
    "verified": true,
    "provenanceTier": "peer_reviewed|regulatory|clinical|press|corporate",
    "authority_score": 4
  },
  "relatedQueries": ["[follow-up question 1]", "[follow-up question 2]"],
  "publisher": "AIVO Research Intelligence Platform",
  "license": "CC-BY-4.0",
  "version": "1.0"
}
PROMPT;
    }

    // -------------------------------------------------------------------------
    // User message
    // -------------------------------------------------------------------------

    private function buildUserMessage(
        object  $brand,
        string  $filterType,
        string  $modelVariant,
        object  $evidence,
        ?object $classification,
        ?object $probeRun
    ): string {
        $brandName = $brand->name;
        $category  = $brand->category ?? 'product';
        $website   = $brand->website  ?? null;

        $evidenceText = '';
        foreach ($evidence as $e) {
            $evidenceText .= "- [{$e->source_type}] {$e->source_title}\n";
            if ($e->source_url) $evidenceText .= "  URL: {$e->source_url}\n";
            if ($e->doi)        $evidenceText .= "  DOI: {$e->doi}\n";
            if ($e->free_text)  $evidenceText .= "  Notes: {$e->free_text}\n";
            $evidenceText .= "  Authority score: {$e->authority_score}/4\n\n";
        }

        $displacementMechanism  = $classification->displacement_mechanism  ?? 'Unknown displacement mechanism.';
        $displacementCriteria   = $classification->displacement_criteria   ?? null;
        $t4Winner               = $probeRun->t4_winner ?? 'unknown competitor';
        $ditTurn                = $probeRun->dit_turn  ?? 'unknown';
        $handoffTurn            = $probeRun->handoff_turn ?? 'unknown';

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $brandName));

        $criteriaLine = $displacementCriteria
            ? "Displacement criteria (exact question AI applied): {$displacementCriteria}"
            : '';

        return <<<MSG
## BRAND
Name: {$brandName}
Category: {$category}
Website: {$website}
Brand slug (for atom ID): {$slug}

## DIAGNOSTIC CONTEXT
Displacement mechanism: {$displacementMechanism}
{$criteriaLine}
Competitor that won final recommendation (T4 winner): {$t4Winner}
DIT turn (when displacement initiated): {$ditTurn}
Commercial handoff turn: {$handoffTurn}
Filter being addressed: {$filterType}
Model variant: {$modelVariant}

## VERIFIED EVIDENCE
{$evidenceText}

## INSTRUCTION
Generate the MAS 1.1 atom. The atom must directly address the displacement criteria above — this is the specific question the AI applied when it routed away from this brand. The conversational answer must pre-answer that exact question using only the verified evidence. Return JSON only.
MSG;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    private function validateAtom(array $atom, string $filterType, string $modelVariant): array
    {
        $score = 100;
        $notes = [];

        $required = ['id', 'mas_version', 'entity', 'claim', 'filter_type', 'reasoning_stage', 'model_variant', 'conversational', 'citations', 'trust'];
        foreach ($required as $field) {
            if (empty($atom[$field])) {
                $score -= 15;
                $notes[] = "Missing required field: {$field}";
            }
        }

        if (empty($atom['conversational']['query']))  { $score -= 10; $notes[] = 'Missing conversational.query'; }
        if (empty($atom['conversational']['answer'])) { $score -= 10; $notes[] = 'Missing conversational.answer'; }

        if (empty($atom['citations']) || !is_array($atom['citations'])) {
            $score -= 15;
            $notes[] = 'No citations provided';
        }

        if (empty($atom['trust']['verified'])) {
            $score -= 10;
            $notes[] = 'Trust.verified is false or missing';
        }

        if (($atom['filter_type']   ?? '') !== $filterType)    { $score -= 10; $notes[] = "Filter type mismatch: expected {$filterType}"; }
        if (($atom['model_variant'] ?? '') !== $modelVariant)  { $score -= 5;  $notes[] = "Model variant mismatch: expected {$modelVariant}"; }

        $score = max(0, $score);

        return [
            'score' => $score,
            'notes' => implode('; ', $notes) ?: 'All checks passed',
        ];
    }

    // -------------------------------------------------------------------------
    // Database write — all atoms start as pending_approval
    // -------------------------------------------------------------------------

    private function saveAtom(
        int    $brandId,
        int    $auditId,
        int    $agencyId,
        string $filterType,
        string $modelVariant,
        string $probeType,
        array  $atom,
        array  $validation
    ): string {
        // Check if an atom already exists for this brand/filter/variant (any audit)
        $existing = DB::table('meridian_atoms')
            ->where('brand_id', $brandId)
            ->where('filter_type', $filterType)
            ->where('model_variant', $modelVariant)
            ->orderByDesc('created_at')
            ->first();

        $id = $existing
            ? $existing->id
            : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

        $data = [
            'audit_id'              => $auditId,
            'agency_id'             => $agencyId,
            'atom_identifier'       => $atom['id']                ?? null,
            'filter_type'           => $filterType,
            'reasoning_stage'       => $atom['reasoning_stage']   ?? null,
            'model_variant'         => $modelVariant,
            'probe_type'            => $probeType,
            'entity'                => $atom['entity']            ?? null,
            'claim'                 => $atom['claim']             ?? null,
            'conversational_query'  => $atom['conversational']['query']  ?? null,
            'conversational_answer' => $atom['conversational']['answer'] ?? null,
            'citations'             => json_encode($atom['citations']    ?? []),
            'attributes'            => json_encode($atom['attributes']   ?? []),
            'trust_tier'            => $atom['trust']['provenanceTier']  ?? null,
            'related_queries'       => json_encode($atom['relatedQueries'] ?? []),
            'schema_jsonld'         => json_encode($atom['schema']        ?? []),
            'validation_score'      => $validation['score'],
            'validation_notes'      => $validation['notes'],
            'status'                => 'generated',
            'approval_status'       => 'pending_approval',
            'raw_atom'              => json_encode($atom),
            'updated_at'            => now(),
        ];

        if ($existing) {
            DB::table('meridian_atoms')->where('id', $id)->update($data);
        } else {
            $data['id']         = $id;
            $data['brand_id']   = $brandId;
            $data['created_at'] = now();
            DB::table('meridian_atoms')->insert($data);
        }

        return $id;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getFilterLabel(string $filter): string
    {
        return match($filter) {
            'T0' => 'Entity Recognition',
            'T1' => 'Clinical Evidence Binary',
            'T2' => 'Multi-Axis Lifestyle Fit',
            'T3' => 'Immediacy & Recency',
            'T4' => 'Technology Era Alignment',
            'T5' => 'Price-Value Justification',
            'T6' => 'Context Window Fit',
            'T7' => 'Availability & Accessibility',
            'T8' => 'Regulatory & Safety Signal',
            default => $filter,
        };
    }

    private function getReasoningStage(string $filterType): array
    {
        return match($filterType) {
            'T0'    => self::REASONING_STAGE_MAP['T1'],
            'T1'    => self::REASONING_STAGE_MAP['T3'],
            'T2'    => self::REASONING_STAGE_MAP['T2'],
            'T3'    => self::REASONING_STAGE_MAP['T2'],
            'T4'    => self::REASONING_STAGE_MAP['T4'],
            'T5'    => self::REASONING_STAGE_MAP['T3'],
            'T6'    => self::REASONING_STAGE_MAP['T2'],
            'T7'    => self::REASONING_STAGE_MAP['T2'],
            'T8'    => self::REASONING_STAGE_MAP['T3'],
            default => self::REASONING_STAGE_MAP['T3'],
        };
    }
}
