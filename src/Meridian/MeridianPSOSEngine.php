<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianPSOSEngine — Platform Stability of Organic Scoring
 *
 * Runs 15 prompt variations across ChatGPT, Gemini, Perplexity.
 * 2 replicates per prompt for statistical reliability.
 * Scores 5 dimensions: Breadth, Depth, Resilience, Sentiment, Decay.
 * Produces Fragile (<40), Moderate (40-69), Strong (>=70) banding.
 * Labelled as PSOS Baseline — single run establishes baseline, decay requires second run.
 */
class MeridianPSOSEngine
{
    private string $proxyUrl;
    private string $claudeApiKey;
    private string $anthropicVersion = '2023-06-01';

    // 15 prompt clusters spanning awareness to purchase intent
    // Breadth: does brand appear across different query types?
    // Depth: how prominently is it recommended?
    // Resilience: does presence hold under competitive framing?
    // Sentiment: positive/neutral/negative brand treatment?
    // Decay: NOT measurable in single run — requires two runs separated by time

    private const PROMPT_TEMPLATES = [
        // Awareness queries (Breadth)
        'awareness_direct'     => "Tell me about {brand} {category}.",
        'awareness_category'   => "What are the best {category_plural} available right now?",
        'awareness_brand_only' => "Is {brand} a good brand?",

        // Consideration queries (Breadth + Depth)
        'consideration_compare'  => "How does {brand} compare to its competitors in {category}?",
        'consideration_worth'    => "Is {brand} worth the money for {category}?",
        'consideration_review'   => "What do experts say about {brand} {product}?",

        // Criteria queries (Resilience)
        'criteria_clinical'    => "What is the best {category} with clinically proven ingredients?",
        'criteria_value'       => "What is the best value {category} that actually works?",
        'criteria_specific'    => "I need a {category} for {use_case}. What would you recommend?",

        // Competitive queries (Resilience)
        'competitive_vs'       => "Which is better: {brand} or {competitor_placeholder}?",
        'competitive_alt'      => "What are the best alternatives to {brand} in {category}?",
        'competitive_category' => "What do most dermatologists recommend for {category}?",

        // Purchase intent queries (Depth + Sentiment)
        'purchase_buy'         => "Where can I buy the best {category}?",
        'purchase_recommend'   => "What {category} would you personally recommend?",
        'purchase_decision'    => "I'm about to buy a {category}. What should I get?",
    ];

    public function __construct()
    {
        $port           = getenv('PORT') ?: '80';
        $this->proxyUrl = 'http://localhost:' . $port . '/api/proxy';
        $this->claudeApiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    }

    /**
     * Run PSOS Baseline for one brand across specified platforms.
     * Called by the background worker.
     */
    public function run(int $auditId, array $platforms, string $brandName, string $category): bool
    {
        error_log("[PSOS] Starting — brand={$brandName} category={$category} platforms=" . implode(',', $platforms));

        // Build the 15 prompts for this brand/category
        $prompts = $this->buildPrompts($brandName, $category);
        $replicates = 2;
        $results = [];

        foreach ($platforms as $platform) {
            $platformResults = [];

            foreach ($prompts as $promptKey => $promptText) {
                $promptScores = [];

                for ($rep = 0; $rep < $replicates; $rep++) {
                    error_log("[PSOS] {$platform} — {$promptKey} rep {$rep}");

                    $modelResult = $this->callModelWithRetry(
                        [['role' => 'user', 'content' => $promptText]],
                        $platform
                    );

                    if (!$modelResult) {
                        $promptScores[] = null;
                        continue;
                    }

                    // Annotate for PSOS dimensions
                    $annotation = $this->annotatePSOS(
                        $modelResult['text'],
                        $brandName,
                        $category,
                        $promptKey
                    );

                    $promptScores[] = $annotation;
                    usleep(300000); // 300ms between calls
                }

                $platformResults[$promptKey] = [
                    'prompt'      => $promptText,
                    'prompt_type' => $this->getPromptType($promptKey),
                    'scores'      => $promptScores,
                ];
            }

            $results[$platform] = $platformResults;
        }

        // Compute dimension scores
        $dimensionScores = $this->computeDimensions($results, $brandName);

        // Compute overall PSOS score (weighted)
        $psosScore = $this->computeOverallScore($dimensionScores);
        $band      = $psosScore >= 70 ? 'Strong' : ($psosScore >= 40 ? 'Moderate' : 'Fragile');

        // Store results
        try {
            DB::table('meridian_brand_audit_results')
                ->where('audit_id', $auditId)
                ->update([
                    'psos_result'  => json_encode([
                        'score'             => $psosScore,
                        'band'              => $band,
                        'dimensions'        => $dimensionScores,
                        'platform_results'  => $results,
                        'brand_name'        => $brandName,
                        'category'          => $category,
                        'platforms'         => $platforms,
                        'replicates'        => $replicates,
                        'prompts_run'       => count($prompts),
                        'decay_note'        => 'Decay dimension requires a second PSOS run after 30+ days to measure score movement.',
                        'completed_at'      => now(),
                    ]),
                    'updated_at' => now(),
                ]);

            error_log("[PSOS] Complete — score={$psosScore} band={$band}");
            return true;

        } catch (\Throwable $e) {
            error_log('[PSOS] Storage failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── PROMPT BUILDING ──────────────────────────────────────────
    private function buildPrompts(string $brandName, string $category): array
    {
        $categoryPlural  = $this->pluralise($category);
        $product         = $brandName; // Use brand name as product proxy
        $useCase         = $this->inferUseCase($category);

        $prompts = [];
        foreach (self::PROMPT_TEMPLATES as $key => $template) {
            $prompts[$key] = str_replace(
                ['{brand}', '{category}', '{category_plural}', '{product}', '{use_case}', '{competitor_placeholder}'],
                [$brandName, $category, $categoryPlural, $product, $useCase, 'leading alternatives'],
                $template
            );
        }

        return $prompts;
    }

    private function getPromptType(string $key): string
    {
        if (str_starts_with($key, 'awareness'))    return 'awareness';
        if (str_starts_with($key, 'consideration')) return 'consideration';
        if (str_starts_with($key, 'criteria'))     return 'criteria';
        if (str_starts_with($key, 'competitive'))  return 'competitive';
        if (str_starts_with($key, 'purchase'))     return 'purchase';
        return 'general';
    }

    private function pluralise(string $category): string
    {
        // Simple English pluralisation for category names
        if (str_ends_with($category, 's')) return $category;
        if (str_ends_with($category, 'y')) return substr($category, 0, -1) . 'ies';
        return $category . 's';
    }

    private function inferUseCase(string $category): string
    {
        $useCaseMap = [
            'moisturiser' => 'anti-ageing and hydration',
            'serum'       => 'brightening and anti-ageing',
            'cleanser'    => 'sensitive skin',
            'foundation'  => 'everyday wear and medium coverage',
            'sunscreen'   => 'daily SPF protection',
            'shampoo'     => 'damaged and colour-treated hair',
        ];
        foreach ($useCaseMap as $word => $useCase) {
            if (str_contains(strtolower($category), $word)) return $useCase;
        }
        return 'everyday use';
    }

    // ── MODEL CALL ───────────────────────────────────────────────
    private function callModelWithRetry(array $messages, string $platform, int $maxRetries = 2): ?array
    {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if ($attempt > 0) { sleep(5); }
            $result = $this->callProxy($messages, $platform);
            if ($result !== null) return $result;
        }
        return null;
    }

    private function callProxy(array $messages, string $platform): ?array
    {
        $payload = json_encode([
            'platform'   => $platform,
            'messages'   => $messages,
            'system'     => 'You are a helpful AI assistant answering consumer questions about products and brands. Respond naturally and helpfully.',
            'max_tokens' => 600,
        ]);

        $ch = curl_init($this->proxyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $httpCode < 200 || $httpCode >= 300) return null;

        // Parse SSE stream
        $text = '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) continue;
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') continue;
            $obj   = json_decode($data, true);
            $chunk = $obj['choices'][0]['delta']['content'] ?? null;
            if ($chunk) $text .= $chunk;
        }

        return $text ? ['text' => $text] : null;
    }

    // ── ANNOTATION ───────────────────────────────────────────────
    private function annotatePSOS(
        string $responseText,
        string $brandName,
        string $category,
        string $promptType
    ): ?array {
        if (!$this->claudeApiKey) return null;

        $prompt = <<<PROMPT
Analyse this AI response to a consumer query about "{$brandName}" ({$category}).
Prompt type: {$promptType}

Response:
"""{$this->truncate($responseText, 1500)}"""

Return ONLY valid JSON:
{
  "brand_mentioned": true/false,
  "brand_position": "primary|secondary|mentioned|absent",
  "recommendation_strength": "strong|moderate|weak|none",
  "sentiment": "positive|neutral|negative|mixed",
  "evidence": "one sentence describing how the brand appears in this response"
}
PROMPT;

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 300,
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
            CURLOPT_TIMEOUT => 20,
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

    // ── DIMENSION SCORING ────────────────────────────────────────
    private function computeDimensions(array $results, string $brandName): array
    {
        $breadthScores     = [];
        $depthScores       = [];
        $resilienceScores  = [];
        $sentimentScores   = [];

        foreach ($results as $platform => $platformResults) {
            foreach ($platformResults as $promptKey => $promptData) {
                $type   = $promptData['prompt_type'];
                $scores = array_filter($promptData['scores']); // Remove nulls

                if (empty($scores)) continue;

                foreach ($scores as $score) {
                    if (!$score) continue;

                    // Breadth: was brand mentioned at all?
                    $mentioned = (bool)($score['brand_mentioned'] ?? false);
                    $breadthScores[] = $mentioned ? 100 : 0;

                    // Depth: how prominently?
                    $position = $score['brand_position'] ?? 'absent';
                    $depthScores[] = match($position) {
                        'primary'   => 100,
                        'secondary' => 60,
                        'mentioned' => 30,
                        default     => 0,
                    };

                    // Resilience: specifically for competitive/criteria prompts
                    if (in_array($type, ['criteria', 'competitive'], true)) {
                        $resilienceScores[] = $mentioned ? (
                            $position === 'primary' ? 100 : ($position === 'secondary' ? 50 : 20)
                        ) : 0;
                    }

                    // Sentiment: positive treatment when mentioned
                    if ($mentioned) {
                        $sentiment = $score['sentiment'] ?? 'neutral';
                        $sentimentScores[] = match($sentiment) {
                            'positive' => 100,
                            'neutral'  => 60,
                            'mixed'    => 40,
                            'negative' => 0,
                            default    => 60,
                        };
                    }
                }
            }
        }

        $avg = fn($arr) => count($arr) > 0 ? (int)round(array_sum($arr) / count($arr)) : 0;

        return [
            'breadth'    => ['score' => $avg($breadthScores),    'label' => 'Breadth',    'desc' => '% of query types where brand is mentioned'],
            'depth'      => ['score' => $avg($depthScores),      'label' => 'Depth',      'desc' => 'Prominence of brand position when mentioned'],
            'resilience' => ['score' => $avg($resilienceScores), 'label' => 'Resilience', 'desc' => 'Brand presence under competitive and criteria framing'],
            'sentiment'  => ['score' => $avg($sentimentScores),  'label' => 'Sentiment',  'desc' => 'Positive/neutral/negative brand treatment'],
            'decay'      => ['score' => null, 'label' => 'Decay', 'desc' => 'Requires second PSOS run after 30+ days. Run baseline now, re-run later to measure score movement.'],
        ];
    }

    private function computeOverallScore(array $dimensions): int
    {
        // Weighted: Breadth 25%, Depth 30%, Resilience 30%, Sentiment 15%
        // Decay excluded from single-run score
        $weights = ['breadth' => 0.25, 'depth' => 0.30, 'resilience' => 0.30, 'sentiment' => 0.15];
        $score   = 0;
        $totalWeight = 0;

        foreach ($weights as $dim => $weight) {
            $dimScore = $dimensions[$dim]['score'] ?? null;
            if ($dimScore !== null) {
                $score       += $dimScore * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? (int)round($score / $totalWeight) : 0;
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
