<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianContentClassifier — ORBIT Phase 1, Step 4 (Classifier)
 *
 * Reads items from meridian_brand_content_items where:
 *   - content_text IS NOT NULL
 *   - classified_at IS NULL  (i.e. not yet classified)
 *
 * Sends each item one-by-one to Claude Haiku with a strict tool_use schema,
 * receives structured JSON for 6 axes + 4 evidence signals + per-axis
 * confidences, and writes results back to Postgres.
 *
 * 6 axes:
 *   - topics                  array<string> (max 5)
 *   - sub_brand               string|null
 *   - territory               string|null   (ISO country code or 'global')
 *   - content_type            enum
 *   - content_date            date|null     (ISO format)
 *   - citation_tier_estimate  T1|T2|T3|unknown
 *
 * 4 evidence signals:
 *   - has_data
 *   - has_external_citations
 *   - has_methodology
 *   - word_count              (computed locally, not from Haiku)
 *
 * Confidence per axis stored in classification_confidences JSONB.
 *
 * URL-domain hard overrides for citation_tier_estimate are applied AFTER
 * Haiku's response, so they always win regardless of model judgement.
 *
 * Cost: Haiku at ~$1/Mtok input + $5/Mtok output. A 5,000-char page ≈ 1,250
 * input tokens + ~400 output ≈ $0.0033 per page. 1,500 pages ≈ $5. Negligible.
 *
 * Idempotency: re-running picks up only NULL classified_at rows. To re-classify
 * an item, set classified_at = NULL.
 */
class MeridianContentClassifier
{
    private const ANTHROPIC_URL    = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MODEL            = 'claude-haiku-4-5-20251001';
    private const MAX_TOKENS       = 1024;

    // Truncate content_text sent to Haiku — full pages can be large but we
    // don't need the whole thing for classification. 12k chars covers the
    // first ~3k tokens which is plenty of signal.
    private const MAX_INPUT_CHARS  = 12_000;

    private const REQUEST_TIMEOUT    = 60;
    private const MAX_RETRIES        = 3;
    private const RETRY_BACKOFF_BASE = 2;

    private const ALLOWED_CONTENT_TYPES = [
        'product', 'article', 'case_study', 'legal', 'landing',
        'documentation', 'pricing', 'homepage', 'about', 'contact', 'other',
    ];

    private const ALLOWED_TIERS = ['T1', 'T2', 'T3', 'unknown'];

    /**
     * Domain → forced citation tier. Applied AFTER Haiku response.
     * Match is on hostname suffix (so 'en.wikipedia.org' matches 'wikipedia.org').
     */
    private const DOMAIN_TIER_OVERRIDES = [
        'wikipedia.org' => 'T1',
        'wikidata.org'  => 'T1',
        'github.com'    => 'T1',
        'reddit.com'    => 'T3',
        'medium.com'    => 'T3',
        'quora.com'     => 'T3',
        'dev.to'        => 'T3',
    ];

    private int $brandId;
    private ?int $sourceId;

    private int $itemsClassified = 0;
    private int $itemsSkipped    = 0;
    private int $itemsFailed     = 0;
    private int $apiCalls        = 0;
    private int $domainOverrides = 0;
    private array $errors        = [];

    public function __construct(int $brandId, ?int $sourceId = null)
    {
        $this->brandId  = $brandId;
        $this->sourceId = $sourceId;
    }

    /**
     * @return array{classified:int, skipped:int, failed:int, api_calls:int, domain_overrides:int, errors:array}
     */
    public function run(): array
    {
        $apiKey = $this->getApiKey();
        $brandContext = $this->fetchBrandContext();

        $this->logInfo("Classifier starting", [
            'brand_id'      => $this->brandId,
            'source_id'     => $this->sourceId,
            'has_brand_ctx' => $brandContext !== null,
        ]);

        $query = DB::table('meridian_brand_content_items')
            ->where('brand_id', $this->brandId)
            ->whereNull('classified_at')
            ->whereNotNull('content_text')
            ->select(['id', 'url', 'title', 'content_text', 'content_date']);

        if ($this->sourceId !== null) {
            $query->where('source_id', $this->sourceId);
        }

        $candidates = $query->orderBy('id', 'asc')->get();
        $totalCandidates = $candidates->count();

        if ($totalCandidates === 0) {
            $this->logInfo("Nothing to classify", ['brand_id' => $this->brandId]);
            return $this->summary();
        }

        $this->logInfo("Classifier candidates", ['count' => $totalCandidates]);

        foreach ($candidates as $idx => $item) {
            try {
                $this->classifyOne($item, $brandContext, $apiKey);

                if (($idx + 1) % 10 === 0) {
                    $this->logInfo("Progress", [
                        'classified' => $this->itemsClassified,
                        'failed'     => $this->itemsFailed,
                        'of'         => $totalCandidates,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->itemsFailed++;
                $this->errors[] = [
                    'item_id' => (int)$item->id,
                    'url'     => $item->url,
                    'error'   => $e->getMessage(),
                ];
                $this->logError("Item failed", [
                    'item_id' => $item->id,
                    'url'     => $item->url,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logInfo("Classifier complete", $this->summary());
        return $this->summary();
    }

    // ─────────────────────────────────────────────────────────────
    // Per-item classification
    // ─────────────────────────────────────────────────────────────

    private function classifyOne(object $item, ?array $brandContext, string $apiKey): void
    {
        $contentText = (string)$item->content_text;
        $wordCount   = $this->countWords($contentText);

        $truncated = mb_strlen($contentText) > self::MAX_INPUT_CHARS
            ? mb_substr($contentText, 0, self::MAX_INPUT_CHARS) . "\n\n[... truncated for classification ...]"
            : $contentText;

        $haiku = $this->callHaiku(
            url:          (string)$item->url,
            title:        (string)($item->title ?? ''),
            contentText:  $truncated,
            brandContext: $brandContext,
            apiKey:       $apiKey,
        );

        // Apply domain-tier override AFTER Haiku response (always wins)
        $tier         = $haiku['citation_tier_estimate'] ?? 'unknown';
        $tierOverride = $this->resolveDomainTier((string)$item->url);
        if ($tierOverride !== null && $tierOverride !== $tier) {
            $this->domainOverrides++;
            $this->logInfo("Domain tier override", [
                'item_id'   => $item->id,
                'url'       => $item->url,
                'haiku_tier'=> $tier,
                'forced_to' => $tierOverride,
            ]);
            $tier = $tierOverride;
        }

        // Validate enums — bail to defaults if Haiku produced bad values
        if (!in_array($tier, self::ALLOWED_TIERS, true)) {
            $tier = 'unknown';
        }

        $contentType = $haiku['content_type'] ?? 'other';
        if (!in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
            $contentType = 'other';
        }

        // Topics: clip to max 5, ensure all strings, deduplicate
        $topics = array_values(array_unique(array_filter(array_map(
            fn($t) => trim((string)$t),
            (array)($haiku['topics'] ?? [])
        ))));
        $topics = array_slice($topics, 0, 5);

        // Confidence floats
        $confidences = $this->normaliseConfidences($haiku['confidences'] ?? []);

        // content_date — prefer Haiku's value, fall back to existing column value
        $contentDate = $haiku['content_date'] ?? null;
        if ($contentDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $contentDate)) {
            $contentDate = null;
        }
        if ($contentDate === null && !empty($item->content_date)) {
            // Keep what crawler already extracted
            $contentDate = is_string($item->content_date)
                ? substr($item->content_date, 0, 10)
                : null;
        }

        // Raw SQL with explicit ::text[] cast for topics — Capsule passes
        // arrays as strings, and Postgres won't always implicitly cast
        // text → text[]. Same pattern as the embedder's ::vector cast.
        // We deliberately do NOT touch last_indexed_at here — that field
        // is owned by the crawler/embedder, which write it on indexing.
        // classified_at alone is the classifier's idempotency anchor.
        DB::statement(
            'UPDATE meridian_brand_content_items
                SET topics                     = ?::text[],
                    sub_brand                  = ?,
                    territory                  = ?,
                    content_type               = ?,
                    content_date               = ?,
                    language                   = ?,
                    citation_tier_estimate     = ?,
                    has_data                   = ?,
                    has_external_citations     = ?,
                    has_methodology            = ?,
                    word_count                 = ?,
                    classification_confidences = ?::jsonb,
                    classified_by              = ?,
                    classified_at              = NOW()
                WHERE id = ?',
            [
                $this->toPgTextArray($topics),
                $this->cleanString($haiku['sub_brand'] ?? null),
                $this->cleanString($haiku['territory'] ?? null),
                $contentType,
                $contentDate,
                $this->cleanString($haiku['language'] ?? null),
                $tier,
                $this->boolToPg((bool)($haiku['has_data'] ?? false)),
                $this->boolToPg((bool)($haiku['has_external_citations'] ?? false)),
                $this->boolToPg((bool)($haiku['has_methodology'] ?? false)),
                $wordCount,
                json_encode($confidences, JSON_UNESCAPED_SLASHES),
                'haiku-' . self::MODEL,
                (int)$item->id,
            ]
        );

        $this->itemsClassified++;
    }

    // ─────────────────────────────────────────────────────────────
    // Anthropic API call with tool_use for guaranteed JSON
    // ─────────────────────────────────────────────────────────────

    private function callHaiku(
        string $url,
        string $title,
        string $contentText,
        ?array $brandContext,
        string $apiKey,
    ): array {
        $brandName       = $brandContext['brand_name'] ?? 'this brand';
        $brandDescription = $brandContext['description'] ?? '';
        $brandSubBrands  = $brandContext['sub_brands'] ?? [];

        $systemPrompt = $this->buildSystemPrompt($brandName, $brandDescription, $brandSubBrands);
        $userPrompt   = $this->buildUserPrompt($url, $title, $contentText);
        $tool         = $this->buildClassificationTool();

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $systemPrompt,
            'tools'      => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => 'classify_content'],
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode Haiku request payload');
        }

        $attempt    = 0;
        $lastError  = '';
        $lastStatus = 0;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $this->apiCalls++;

            $ch = curl_init(self::ANTHROPIC_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: ' . self::ANTHROPIC_VERSION,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response   = curl_exec($ch);
            $httpCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno  = curl_errno($ch);
            $curlError  = curl_error($ch);
            curl_close($ch);

            $lastStatus = $httpCode;

            if ($curlErrno !== 0) {
                $lastError = "curl error {$curlErrno}: {$curlError}";
                $this->backoff($attempt);
                continue;
            }

            if ($httpCode === 200) {
                $decoded = json_decode((string)$response, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException(
                        'Haiku 200 but malformed JSON: ' . substr((string)$response, 0, 300)
                    );
                }

                // Extract the tool_use block
                foreach (($decoded['content'] ?? []) as $block) {
                    if (($block['type'] ?? '') === 'tool_use'
                        && ($block['name'] ?? '') === 'classify_content'
                        && isset($block['input']) && is_array($block['input'])
                    ) {
                        return $block['input'];
                    }
                }

                throw new \RuntimeException(
                    'Haiku response missing tool_use block: ' . substr((string)$response, 0, 300)
                );
            }

            if ($httpCode === 429 || $httpCode >= 500) {
                $lastError = "HTTP {$httpCode}: " . substr((string)$response, 0, 300);
                $this->backoff($attempt);
                continue;
            }

            // Non-retryable
            throw new \RuntimeException(
                "Haiku HTTP {$httpCode}: " . substr((string)$response, 0, 500)
            );
        }

        throw new \RuntimeException(
            "Haiku failed after {$attempt} attempts. Last status: {$lastStatus}. Last error: {$lastError}"
        );
    }

    private function backoff(int $attempt): void
    {
        sleep(self::RETRY_BACKOFF_BASE ** $attempt);
    }

    // ─────────────────────────────────────────────────────────────
    // Prompt + tool schema construction
    // ─────────────────────────────────────────────────────────────

    private function buildSystemPrompt(string $brandName, string $brandDescription, array $subBrands): string
    {
        $subBrandList = '';
        if (!empty($subBrands)) {
            $names = array_filter(array_map(
                fn($s) => is_array($s) ? ($s['name'] ?? null) : (is_string($s) ? $s : null),
                $subBrands
            ));
            if (!empty($names)) {
                $subBrandList = "\n\nKnown sub-brands / product lines for {$brandName}: "
                    . implode(', ', array_slice($names, 0, 30));
            }
        }

        $brandLine = $brandDescription !== ''
            ? "Brand context for {$brandName}: {$brandDescription}"
            : "Brand: {$brandName}";

        return <<<PROMPT
You are a content classification system for AIVO, a brand intelligence platform.

You classify a single web page into structured fields, returning your output via the `classify_content` tool.

{$brandLine}{$subBrandList}

Definitions:
- topics: 1-5 short noun phrases describing what the page is about (lowercase, no punctuation). Free-form, not a fixed taxonomy. Example: ["anti-ageing", "serum", "skincare routine"]. Always present.
- sub_brand: If the page is about a specific named product line within {$brandName}, return that name exactly. OMIT this field if generic.
- territory: ISO 3166-1 alpha-2 country code (e.g. "GB", "US") if the page is geographically targeted, "global" if not. OMIT if undetectable.
- content_type: One of product, article, case_study, legal, landing, documentation, pricing, homepage, about, contact, other. Always present.
- content_date: Publication or last-updated date in YYYY-MM-DD if confidently detectable from the page. OMIT otherwise.
- language: ISO 639-1 (e.g. "en", "fr") of the page's primary language. OMIT if undetectable.
- citation_tier_estimate: AIVO citation tier framework. Always present.
    * T1 = encyclopedic / canonical training-data sources (Wikipedia, GitHub READMEs, peer-reviewed papers, official docs)
    * T2 = industry authority (trade journals, regulatory bodies, professional associations, technical authorities)
    * T3 = immediacy / community layer (Reddit, Medium, news, blog posts, forums)
    * unknown = doesn't naturally fit (most marketing/landing/product pages)
  Estimate based on the page's evidence quality, citation patterns, and authorial tone — NOT just URL.
- has_data: true if the page contains numerical data, statistics, percentages, or charts. Always present.
- has_external_citations: true if the page references third-party studies, journals, or external authoritative sources. Always present.
- has_methodology: true if the page describes how something was measured, tested, or evaluated. Always present.

For every axis (including any you OMITTED), return a confidence score 0.0–1.0 in the `confidences` object. For omitted fields, the confidence reflects how sure you are it should be omitted.

Be calibrated — low confidence on uncertain calls is more useful than confident wrong answers.
PROMPT;
    }

    private function buildUserPrompt(string $url, string $title, string $contentText): string
    {
        return "Classify this page:\n\nURL: {$url}\nTitle: {$title}\n\n--- CONTENT ---\n{$contentText}";
    }

    private function buildClassificationTool(): array
    {
        // Anthropic's tool_use schema doesn't reliably support `type: ["string", "null"]`
        // for nullable fields. Pattern: make the field optional (omit from `required`),
        // and document nullability in the description so Haiku knows it can omit.
        // We list only NOT-NULL fields in `required`.
        return [
            'name'        => 'classify_content',
            'description' => 'Return the classification for the page across all required axes.',
            'input_schema' => [
                'type'       => 'object',
                'required'   => [
                    // Always-present fields. sub_brand, territory, content_date, language
                    // are nullable — Haiku may omit them when not detectable.
                    'topics', 'content_type', 'citation_tier_estimate',
                    'has_data', 'has_external_citations', 'has_methodology',
                    'confidences',
                ],
                'properties' => [
                    'topics' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => '1-5 short topic noun phrases (lowercase, no punctuation).',
                    ],
                    'sub_brand' => [
                        'type'        => 'string',
                        'description' => 'Sub-brand or product line name. OMIT this field if the page is generic (i.e. not about a specific named product line).',
                    ],
                    'territory' => [
                        'type'        => 'string',
                        'description' => 'ISO 3166-1 alpha-2 country code, "global", or OMIT if undetectable.',
                    ],
                    'content_type' => [
                        'type' => 'string',
                        'enum' => self::ALLOWED_CONTENT_TYPES,
                    ],
                    'content_date' => [
                        'type'        => 'string',
                        'description' => 'YYYY-MM-DD format. OMIT this field if no confident date can be detected.',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'ISO 639-1 code (e.g. "en", "fr"). OMIT if undetectable.',
                    ],
                    'citation_tier_estimate' => [
                        'type' => 'string',
                        'enum' => self::ALLOWED_TIERS,
                    ],
                    'has_data'               => ['type' => 'boolean'],
                    'has_external_citations' => ['type' => 'boolean'],
                    'has_methodology'        => ['type' => 'boolean'],
                    'confidences' => [
                        'type'       => 'object',
                        'description'=> 'Per-axis confidence 0.0–1.0 for every axis (including ones you omitted, where confidence reflects how sure you are it should be omitted).',
                        'properties' => [
                            'topics'                 => ['type' => 'number'],
                            'sub_brand'              => ['type' => 'number'],
                            'territory'              => ['type' => 'number'],
                            'content_type'           => ['type' => 'number'],
                            'content_date'           => ['type' => 'number'],
                            'language'               => ['type' => 'number'],
                            'citation_tier_estimate' => ['type' => 'number'],
                            'has_data'               => ['type' => 'number'],
                            'has_external_citations' => ['type' => 'number'],
                            'has_methodology'        => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Brand context fetch
    // ─────────────────────────────────────────────────────────────

    /**
     * Fetch brand context if available — used to seed the classifier
     * with brand name, description, and known sub-brand list.
     *
     * Returns null if no context exists, which is fine: classifier still works,
     * just less accurate on sub_brand calls.
     */
    private function fetchBrandContext(): ?array
    {
        $brand = DB::table('meridian_brands')->where('id', $this->brandId)->first();
        if (!$brand) return null;

        $context = [
            'brand_name'  => (string)$brand->name,
            'description' => '',
            'sub_brands'  => [],
        ];

        // Optional: pull from meridian_brand_context if the row exists
        try {
            $ctxRow = DB::table('meridian_brand_context')
                ->where('brand_id', $this->brandId)
                ->orderBy('id', 'desc')
                ->first();

            if ($ctxRow) {
                if (!empty($ctxRow->context_summary)) {
                    $context['description'] = mb_substr((string)$ctxRow->context_summary, 0, 500);
                }
                if (!empty($ctxRow->sub_brands)) {
                    $decoded = is_string($ctxRow->sub_brands)
                        ? json_decode($ctxRow->sub_brands, true)
                        : (array)$ctxRow->sub_brands;
                    if (is_array($decoded)) {
                        $context['sub_brands'] = $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Brand context table or columns may not exist — fail silent.
            $this->logInfo("Brand context fetch skipped", ['reason' => $e->getMessage()]);
        }

        return $context;
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Resolve a URL to a forced citation tier via DOMAIN_TIER_OVERRIDES,
     * matching on hostname suffix. Returns null if no override applies.
     */
    private function resolveDomainTier(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        $host = strtolower($host);

        foreach (self::DOMAIN_TIER_OVERRIDES as $domain => $tier) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $tier;
            }
        }
        return null;
    }

    private function countWords(string $text): int
    {
        // Simple Unicode-safe word count: split on whitespace runs.
        $text = trim($text);
        if ($text === '') return 0;
        $parts = preg_split('/\s+/u', $text);
        return is_array($parts) ? count($parts) : 0;
    }

    private function cleanString(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        return $s === '' ? null : $s;
    }

    /**
     * Postgres boolean binding via PDO is finicky — a PHP `false` may bind as
     * empty string and confuse the typed column. Pass 't'/'f' literals which
     * Postgres reliably coerces to BOOLEAN.
     */
    private function boolToPg(bool $b): string
    {
        return $b ? 't' : 'f';
    }

    private function normaliseConfidences(array $raw): array
    {
        $axes = [
            'topics', 'sub_brand', 'territory', 'content_type', 'content_date',
            'language', 'citation_tier_estimate',
            'has_data', 'has_external_citations', 'has_methodology',
        ];

        $out = [];
        foreach ($axes as $axis) {
            $v = $raw[$axis] ?? null;
            if (is_numeric($v)) {
                $out[$axis] = max(0.0, min(1.0, (float)$v));
            } else {
                $out[$axis] = null;
            }
        }
        return $out;
    }

    /**
     * Format an array of strings as a Postgres TEXT[] literal: '{a,b,c}'.
     * Each element is escaped with double quotes and backslash-escaped.
     */
    private function toPgTextArray(array $items): string
    {
        if (empty($items)) return '{}';
        $escaped = array_map(function ($item) {
            $s = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$item);
            return '"' . $s . '"';
        }, $items);
        return '{' . implode(',', $escaped) . '}';
    }

    private function getApiKey(): string
    {
        $key = getenv('ANTHROPIC_API_KEY') ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        if (!$key) {
            throw new \RuntimeException('ANTHROPIC_API_KEY env var is not set');
        }
        return $key;
    }

    public function getStats(): array
    {
        return $this->summary();
    }

    private function summary(): array
    {
        return [
            'classified'       => $this->itemsClassified,
            'skipped'          => $this->itemsSkipped,
            'failed'           => $this->itemsFailed,
            'api_calls'        => $this->apiCalls,
            'domain_overrides' => $this->domainOverrides,
            'errors'           => $this->errors,
        ];
    }

    private function logInfo(string $msg, array $context = []): void
    {
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[ContentClassifier] {$msg}{$ctx}");
    }

    private function logError(string $msg, array $context = []): void
    {
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[ContentClassifier] ERROR: {$msg}{$ctx}");
    }
}
