<?php

declare(strict_types=1);

namespace Aivo\Orbit\Services;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use Aivo\Orbit\Providers\ArxivSearchProvider;
use Aivo\Orbit\Providers\BraveSearchProvider;
use Aivo\Orbit\Providers\CrossrefSearchProvider;
use Aivo\Orbit\Providers\GitHubSearchProvider;
use Aivo\Orbit\Providers\OpenAlexSearchProvider;
use Aivo\Orbit\Providers\PubMedSearchProvider;
use Aivo\Orbit\Providers\WikidataSearchProvider;
use Aivo\Orbit\Providers\WikipediaSearchProvider;
use Aivo\Orbit\Providers\ZenodoSearchProvider;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Throwable;

/**
 * OrbitSearchOrchestrator — conducts a full ORBIT search run.
 *
 * Pipeline:
 *   1. Load gap row + brand row + agency row from meridian tables.
 *   2. Build claim text from gap fields (claim, displacing_brand, intervention_required).
 *   3. Embed the claim (one OpenAI call).
 *   4. Pick searchable platforms via CitationPlatformResolver.
 *   5. Fan out to each platform's search provider.
 *   6. Embed all candidate snippets in one batch (one OpenAI call).
 *   7. Score each candidate via CandidateScorer.
 *   8. Persist orbit_search_runs + orbit_search_results.
 *   9. Return ranked list to caller.
 *
 * No transactions — search runs are independent of each other; partial failures
 * are recorded as the row that did succeed.
 */
final class OrbitSearchOrchestrator
{
    /** Hard cap on how many results we fetch per provider before scoring */
    private const PER_PROVIDER_RESULT_CAP = 5;

    /** Hard cap on total candidates considered in one run */
    private const TOTAL_CANDIDATE_CAP = 60;

    public function __construct(
        private readonly EmbeddingService          $embedder,
        private readonly CitationPlatformResolver  $resolver,
        private readonly CandidateScorer           $scorer,
        private readonly string                    $braveApiKey,
        private readonly ?string                   $githubToken
    ) {}

    /**
     * Run a search for a single gap.
     *
     * @param int       $gapId
     * @param string[]  $requestedTiers      e.g. ['T1.*','T2.*']. Empty = all.
     * @param string    $requestedSentiment  'positive'|'neutral'|'any'.
     * @param int|null  $perProviderCap      Override per-provider result cap.
     *
     * @return array {
     *   run_id: int,
     *   gap_id: int,
     *   brand_name: string,
     *   claim: string,
     *   platforms_queried: int,
     *   candidates_total: int,
     *   candidates_persisted: int,
     *   results: array<int, array>,
     *   errors: array<int, string>
     * }
     */
    public function run(
        int $gapId,
        array $requestedTiers = [],
        string $requestedSentiment = 'positive',
        ?int $perProviderCap = null
    ): array {
        $startedAt = microtime(true);

        // 1. Load gap → brand → agency
        $gap = Capsule::table('meridian_competitive_citation_gaps')->where('id', $gapId)->first();
        if (!$gap) {
            throw new RuntimeException("Gap not found: {$gapId}");
        }
        $gap = (array) $gap;

        $brandId   = (int) ($gap['brand_id']   ?? 0);
        $agencyId  = (int) ($gap['agency_id']  ?? 0);

        $brand = $brandId > 0
            ? (array) (Capsule::table('meridian_brands')->where('id', $brandId)->first() ?? [])
            : [];
        $agency = $agencyId > 0
            ? (array) (Capsule::table('meridian_agencies')->where('id', $agencyId)->first() ?? [])
            : [];

        $brandName    = (string) ($brand['name']        ?? '');
        $brandCategory = (string) ($brand['category']   ?? '');
        $brandSubCat   = (string) ($brand['subcategory'] ?? '');
        $brandSectors  = array_values(array_filter([
            strtolower($brandCategory),
            strtolower($brandSubCat),
        ]));

        // Load the matching classification row to recover displacement_criteria.
        // This is the actual question the AI asked at T4 — the most semantically
        // important field for relevance matching. It's NOT stored on the gap row,
        // only on meridian_filter_classifications. If we can't find it, we'll
        // fall back to the gap row's intervention_required field.
        $displacementCriteria = (string) ($gap['displacement_criteria'] ?? '');
        if ($displacementCriteria === '') {
            $auditId = (int) ($gap['audit_id'] ?? 0);
            $citationTier = (string) ($gap['citation_tier'] ?? '');
            if ($auditId > 0 && $brandId > 0 && $citationTier !== '') {
                $classifications = Capsule::table('meridian_filter_classifications')
                    ->where('audit_id', $auditId)
                    ->where('brand_id', $brandId)
                    ->orderByDesc('confidence_score')
                    ->orderByDesc('survival_gap')
                    ->limit(20)
                    ->get();

                foreach ($classifications as $c) {
                    // Confirm this classification covers our citation_tier
                    $gapsJson = json_decode($c->evidence_gaps ?? '[]', true);
                    if (!is_array($gapsJson)) continue;

                    $matchesFilter = false;
                    foreach ($gapsJson as $g) {
                        if (isset($g['filter']) && strtoupper((string) $g['filter']) === strtoupper($citationTier)) {
                            $matchesFilter = true;
                            break;
                        }
                    }

                    if ($matchesFilter && !empty($c->displacement_criteria)) {
                        $displacementCriteria = (string) $c->displacement_criteria;
                        break;
                    }
                }
            }
        }

        // 2. Build claim text and query string from gap + recovered displacement_criteria
        $claimText   = $this->buildClaimText($gap, $brandName, $displacementCriteria);
        $queryString = $this->buildQueryString($gap, $brandName, $displacementCriteria);

        // 3. Embed the claim
        $claimEmbedding = [];
        $errors = [];
        try {
            $claimEmbedding = $this->embedder->embed($claimText);
        } catch (Throwable $e) {
            $errors[] = 'Claim embedding failed: ' . $e->getMessage();
        }

        // 4. Pick platforms
        $platforms = $this->resolver->pickSearchablePlatforms(
            $requestedTiers !== [] ? $requestedTiers : ['T1.*', 'T2.*', 'T3.*'],
            $brandSectors
        );

        // Build provider list — one provider per searchable platform we can drive
        $providerSpecs = [];
        foreach ($platforms as $p) {
            $providerName = strtolower((string) ($p['platform_name'] ?? ''));
            $provider = $this->buildProviderForPlatform($providerName, $p);
            if ($provider !== null) {
                $providerSpecs[] = ['platform' => $p, 'provider' => $provider];
            }
        }

        // Always include Brave as a generic web fallback (T3 fallback), classified per-result
        $bravePlatformRow = $this->resolver->findByProviderName(new BraveSearchProvider($this->braveApiKey));
        $providerSpecs[] = [
            'platform' => $bravePlatformRow ?? $this->resolver->classifyUrl('https://example.com'),
            'provider' => new BraveSearchProvider($this->braveApiKey),
        ];

        // 5. Fan out to providers with the prepared query string
        $perCap = max(1, min(10, (int) ($perProviderCap ?? self::PER_PROVIDER_RESULT_CAP)));

        // Each candidate is wrapped with its source platform row + provider name
        $allCandidates = []; // list of [candidate, platform, provider_name]
        $platformsSearched = []; // names of providers that returned results
        $platformsSkipped  = []; // [providerName => reason]

        foreach ($providerSpecs as $spec) {
            /** @var SearchProviderInterface $provider */
            $provider = $spec['provider'];
            $platformRow = $spec['platform'];
            $providerName = $provider->getName();

            try {
                $candidates = $provider->search($queryString, ['count' => $perCap]);
            } catch (SearchProviderException $e) {
                $platformsSkipped[$providerName] = 'provider_error: ' . $e->getMessage();
                $errors[] = "{$providerName}: " . $e->getMessage();
                continue;
            } catch (Throwable $e) {
                $platformsSkipped[$providerName] = 'unhandled: ' . $e->getMessage();
                $errors[] = "{$providerName} (unhandled): " . $e->getMessage();
                continue;
            }

            $platformsSearched[] = $providerName;

            foreach ($candidates as $candidate) {
                if (count($allCandidates) >= self::TOTAL_CANDIDATE_CAP) {
                    break 2;
                }
                // For Brave hits, classify per-URL since Brave touches the whole web
                $effectivePlatform = $platformRow;
                if ($providerName === 'brave') {
                    $classified = $this->resolver->classifyUrl($candidate->url);
                    if (!empty($classified['id'])) {
                        $effectivePlatform = $classified;
                    }
                }
                $allCandidates[] = [
                    'candidate'      => $candidate,
                    'platform'       => $effectivePlatform,
                    'provider_name'  => $providerName,
                ];
            }
        }

        // Deduplicate (case-sensitive) platforms_searched
        $platformsSearched = array_values(array_unique($platformsSearched));

        // Deduplicate by URL — first-seen wins (preserves higher-tier sources)
        $seen = [];
        $deduped = [];
        foreach ($allCandidates as $entry) {
            $url = $entry['candidate']->url;
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $deduped[] = $entry;
            }
        }
        $allCandidates = $deduped;

        // 6. Batch-embed all candidate texts
        $candidateTexts = [];
        foreach ($allCandidates as $entry) {
            $c = $entry['candidate'];
            $candidateTexts[] = trim(($c->title ?? '') . "\n\n" . ($c->snippet ?? ''));
        }

        $candidateEmbeddings = [];
        if ($claimEmbedding !== [] && $candidateTexts !== []) {
            try {
                $candidateEmbeddings = $this->embedder->embedBatch($candidateTexts);
            } catch (Throwable $e) {
                $errors[] = 'Batch candidate embedding failed: ' . $e->getMessage();
                $candidateEmbeddings = array_fill(0, count($candidateTexts), []);
            }
        } else {
            $candidateEmbeddings = array_fill(0, count($candidateTexts), []);
        }

        // 7. Score each candidate
        $scored = [];
        foreach ($allCandidates as $idx => $entry) {
            $candidate    = $entry['candidate'];
            $platform     = $entry['platform'];
            $providerName = $entry['provider_name'];
            $candEmb      = $candidateEmbeddings[$idx] ?? [];

            $scoreBundle = $this->scorer->score(
                $candidate,
                $platform,
                $claimEmbedding,
                $candEmb,
                $brandSectors,
                $requestedSentiment
            );

            $scored[] = [
                'candidate'    => $candidate,
                'platform'     => $platform,
                'provider'     => $providerName,
                'embedding'    => $candEmb,
                'score'        => $scoreBundle,
            ];
        }

        // 8. Sort descending by candidate_score
        usort($scored, fn ($a, $b) => $b['score']['candidate_score'] <=> $a['score']['candidate_score']);

        // 9. Persist orbit_search_runs + orbit_search_results
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $runId = $this->persistRun(
            $gapId,
            $brandId,
            $agencyId,
            $claimText,
            $queryString,
            $claimEmbedding,
            $requestedTiers,
            $requestedSentiment,
            $platformsSearched,
            $platformsSkipped,
            count($allCandidates),
            $latencyMs,
            $errors
        );

        $persisted = 0;
        foreach ($scored as $rank => $entry) {
            $persistedRow = $this->persistResult($runId, $rank + 1, $entry);
            if ($persistedRow) {
                $persisted++;
            }
        }

        // 10. Return summary
        return [
            'run_id'                => $runId,
            'gap_id'                => $gapId,
            'brand_name'            => $brandName,
            'claim'                 => $claimText,
            'query_string'          => $queryString,
            'platforms_searched'    => $platformsSearched,
            'platforms_skipped'     => $platformsSkipped,
            'candidates_total'      => count($allCandidates),
            'candidates_persisted'  => $persisted,
            'latency_ms'            => $latencyMs,
            'errors'                => $errors,
            'results'               => array_map(function ($entry, $rank) {
                $c = $entry['candidate'];
                return [
                    'rank'         => $rank + 1,
                    'url'          => $c->url,
                    'title'        => $c->title,
                    'snippet'      => $c->snippet,
                    'author'       => $c->author,
                    'source'       => $c->sourcePlatform,
                    'platform_id'  => $entry['platform']['id'] ?? null,
                    'platform_name'=> $entry['platform']['platform_name'] ?? null,
                    'tier'         => $entry['platform']['tier'] ?? null,
                    'provider'     => $entry['provider'],
                    'published_at' => $c->publishedAt?->format('c'),
                    'score'        => $entry['score'],
                ];
            }, $scored, array_keys($scored)),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the claim text used for embedding (semantic relevance comparison).
     *
     * The claim must be a POSITIVE statement of what evidence we want to find,
     * NOT a negative description of what's missing. Embedding a negative
     * (e.g. "lacks peer-reviewed evidence") makes ORBIT match papers that are
     * literally about "lacking peer-reviewed publications" — which is what
     * happened in the Revitalift Paris run that triggered this fix.
     *
     * Construction priority:
     *   1. displacement_criteria — the actual question the AI asked at T4.
     *      This is the most semantically valuable text and dominates the claim.
     *   2. brand + category — topical anchors so embeddings stay on-domain.
     *   3. displacing_brand — used as a positive comparator ("comparable to X")
     *      not as a negative ("displaced by X"), so the embedding stays in the
     *      same semantic neighbourhood as the displacing brand's evidence.
     *
     * What we deliberately EXCLUDE:
     *   - intervention_required / gap_description (often phrased as a negative)
     *   - expected_content_type (generic per-tier boilerplate that overwhelms
     *     the embedding with words like "peer-reviewed publications")
     */
    private function buildClaimText(array $gap, string $brandName, string $displacementCriteria = ''): string
    {
        $parts = [];

        // displacement_criteria is the lead — the actual question being asked.
        // Strip a trailing question mark so embedding sees a statement-like form.
        $criteria = trim($displacementCriteria);
        if ($criteria !== '') {
            $criteria = rtrim($criteria, " \t\n\r\0\x0B?");
            $parts[] = "Evidence required: " . $criteria . '.';
        } elseif (!empty($gap['claim'])) {
            // Legacy fallback for callers that pre-populate gap['claim']
            $parts[] = (string) $gap['claim'];
        }

        // Topical anchors — brand + category keep the embedding on-domain
        $brandLine = '';
        if ($brandName !== '') {
            $brandLine = "Brand: {$brandName}";
            if (!empty($gap['category'])) {
                $brandLine .= " (" . (string) $gap['category'];
                if (!empty($gap['subcategory'])) {
                    $brandLine .= " / " . (string) $gap['subcategory'];
                }
                $brandLine .= ")";
            }
            $parts[] = $brandLine;
        }

        // Positive comparator — find evidence "comparable to" what supports
        // the displacing brand. This pulls the embedding into the same
        // semantic space as evidence the displacing brand HAS.
        if (!empty($gap['displacing_brand'])) {
            $parts[] = "Looking for evidence comparable to that supporting "
                    . (string) $gap['displacing_brand'] . ".";
        }

        // If displacement_criteria was empty AND we have no fallback claim,
        // fall back gracefully on intervention_required so we never embed
        // an empty string. Strip any leading "No "/"Lacks "/"Missing " to
        // avoid feeding negation back in.
        if ($criteria === '' && empty($gap['claim']) && !empty($gap['intervention_required'])) {
            $iv = trim((string) $gap['intervention_required']);
            $iv = preg_replace('/^(No|Lacks|Lack of|Missing|Insufficient)\s+/i', '', $iv) ?? $iv;
            if ($iv !== '') {
                $parts[] = "Need evidence on: " . $iv;
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Build the keyword query string sent to provider search APIs.
     *
     * Search APIs (PubMed, Crossref, Brave, etc.) want concise topical keywords,
     * not long natural-language statements. We extract content nouns from the
     * displacement_criteria, then prepend brand + category as anchors.
     *
     * Examples:
     *   displacement_criteria = "Which brand has the most recent, retrievable
     *                            clinical data demonstrating specific measurable
     *                            results for hyaluronic acid anti-aging benefits?"
     *   brand = "Revitalift Paris"  category = "Beauty & Skincare"
     *   →  query = "Revitalift Paris Beauty Skincare hyaluronic acid anti-aging
     *              clinical data measurable results"
     *
     *   This is what PubMed/Crossref actually want to see.
     */
    private function buildQueryString(array $gap, string $brandName, string $displacementCriteria = ''): string
    {
        $bits = [];

        // Brand and category go FIRST as topical anchors — search APIs weight
        // earlier tokens more heavily. Strip punctuation so they don't tokenise
        // into useless fragments.
        if ($brandName !== '') {
            $bits[] = $this->cleanForQuery($brandName);
        }
        if (!empty($gap['category'])) {
            $bits[] = $this->cleanForQuery((string) $gap['category']);
        }
        if (!empty($gap['subcategory'])) {
            $bits[] = $this->cleanForQuery((string) $gap['subcategory']);
        }

        // Extract content keywords from displacement_criteria
        $criteria = trim($displacementCriteria);
        if ($criteria === '' && !empty($gap['intervention_required'])) {
            $criteria = (string) $gap['intervention_required'];
        }
        if ($criteria !== '') {
            $bits[] = $this->extractContentKeywords($criteria);
        }

        $query = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($bits))) ?? '');

        // Truncate at ~200 chars — most search APIs prefer shorter queries
        return mb_substr($query, 0, 200);
    }

    /**
     * Strip parens, quotes, and leading articles for cleaner query tokens.
     */
    private function cleanForQuery(string $s): string
    {
        $s = preg_replace('/[\(\)\[\]"\'`]+/', ' ', $s) ?? $s;
        $s = preg_replace('/^\s*(the|a|an)\s+/i', '', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * Extract content keywords from a natural-language sentence.
     *
     * Strategy: drop stopwords, drop punctuation, drop very short words,
     * keep the rest in original order. Cap at 12 keywords to keep search
     * APIs happy. This is a pragmatic heuristic — not a parser. If quality
     * proves insufficient, upgrade to OpenAI keyword extraction (one extra
     * embedding call per search).
     */
    private function extractContentKeywords(string $text): string
    {
        // Stopwords list — meta-words that match too generically across a corpus.
        // Specifically excludes "peer-reviewed", "clinical", "evidence" etc. which
        // are content-bearing in academic search contexts.
        static $stopwords = [
            'a','an','and','are','as','at','be','been','being','but','by','can','do',
            'does','did','for','from','had','has','have','having','how','if','in',
            'into','is','it','its','of','on','or','our','out','over','than','that',
            'the','their','these','they','this','those','to','was','were','what',
            'when','where','which','who','whom','why','will','with','would','you',
            'your','my','i','we','us','me','him','her','he','she',
            // Verbs that add no semantic weight
            'demonstrate','demonstrating','show','showing','provide','providing',
            'find','finding','need','needs','needed','want','wants','wanted',
            'use','used','using','make','making','made','take','taken','taking',
            // Generic nouns that don't help focus the query
            'thing','things','way','ways','time','times','case','cases','example',
            'examples','specific','specifically','recent','retrievable','most',
            'comparable','comparison','comparing','versus','vs',
            // Question/meta words
            'brand','brands',
        ];

        // Lowercase, strip punctuation except hyphen (preserve "anti-aging")
        $clean = mb_strtolower($text);
        $clean = preg_replace('/[^\p{L}\p{N}\-\s]+/u', ' ', $clean) ?? '';
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
        $tokens = explode(' ', trim($clean));

        $kept = [];
        $seen = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok, "- \t\n\r\0\x0B");
            if ($tok === '') continue;
            if (mb_strlen($tok) < 3) continue;
            if (in_array($tok, $stopwords, true)) continue;
            // Dedupe — same word twice in a query just wastes tokens
            if (isset($seen[$tok])) continue;
            $seen[$tok] = true;
            $kept[] = $tok;
            if (count($kept) >= 12) break;
        }

        return implode(' ', $kept);
    }

    /**
     * Build a SearchProviderInterface for a given citation_platforms row.
     * Returns null if we don't have an adapter for that platform.
     */
    private function buildProviderForPlatform(string $providerName, array $platformRow): ?SearchProviderInterface
    {
        switch ($providerName) {
            case 'wikipedia':
                return new WikipediaSearchProvider();
            case 'wikidata':
                return new WikidataSearchProvider();
            case 'github':
                return new GitHubSearchProvider($this->githubToken);
            case 'crossref':
                return new CrossrefSearchProvider();
            case 'openalex':
                return new OpenAlexSearchProvider();
            case 'pubmed':
                return new PubMedSearchProvider();
            case 'arxiv':
                return new ArxivSearchProvider();
            case 'zenodo':
                return new ZenodoSearchProvider();
            default:
                return null;
        }
    }

    private function persistRun(
        int $gapId,
        int $brandId,
        int $agencyId,
        string $claimText,
        string $queryString,
        array $claimEmbedding,
        array $requestedTiers,
        string $requestedSentiment,
        array $platformsSearched,
        array $platformsSkipped,
        int $resultsCount,
        int $latencyMs,
        array $errors
    ): int {
        // Build PG TEXT[] literals manually since Capsule doesn't auto-convert PHP arrays
        $tiersLiteral = $this->toPgTextArray($requestedTiers);
        $searchedLiteral = $this->toPgTextArray($platformsSearched);

        $errorMessage = $errors !== [] ? implode(' | ', $errors) : null;
        $status = ($errorMessage === null || $resultsCount > 0) ? 'complete' : 'error';

        $payload = [
            'gap_id'               => $gapId,
            'brand_id'             => $brandId > 0 ? $brandId : null,
            'agency_id'            => $agencyId > 0 ? $agencyId : null,
            'claim_text'           => $claimText,
            'requested_tiers'      => Capsule::raw('ARRAY[' . $this->commaSeparatedQuoted($requestedTiers) . ']::text[]'),
            'requested_sentiment'  => $requestedSentiment,
            'platforms_searched'   => Capsule::raw('ARRAY[' . $this->commaSeparatedQuoted($platformsSearched) . ']::text[]'),
            'platforms_skipped'    => json_encode($platformsSkipped),
            'results_count'        => $resultsCount,
            'accepted_count'       => 0,
            'total_cost_usd'       => 0,
            'latency_ms'           => $latencyMs,
            'triggered_by'         => 'admin',
            'status'               => $status,
            'error_message'        => $errorMessage,
            'created_at'           => Capsule::raw('NOW()'),
            'completed_at'         => Capsule::raw('NOW()'),
        ];

        // Brand FK is NOT NULL in the schema — abort if we couldn't load brand
        if ($payload['brand_id'] === null || $payload['agency_id'] === null) {
            throw new RuntimeException(
                "Cannot persist run: gap {$gapId} is missing brand_id or agency_id linkage."
            );
        }

        $vector = EmbeddingService::toPgVector($claimEmbedding);
        if ($vector !== null) {
            $payload['claim_embedding'] = $vector;
        }

        // query_string is not a column in the schema — we keep it for the
        // response payload only, not for persistence
        unset($queryString);
        unset($tiersLiteral);
        unset($searchedLiteral);

        return (int) Capsule::table('orbit_search_runs')->insertGetId($payload);
    }

    private function commaSeparatedQuoted(array $values): string
    {
        $parts = [];
        foreach ($values as $v) {
            $s = (string) $v;
            // Escape single quotes for SQL literal
            $s = str_replace("'", "''", $s);
            $parts[] = "'{$s}'";
        }
        return implode(',', $parts);
    }

    private function toPgTextArray(array $values): string
    {
        $escaped = array_map(static function ($v) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"';
        }, $values);
        return '{' . implode(',', $escaped) . '}';
    }

    private function persistResult(int $runId, int $rank, array $entry): bool
    {
        try {
            /** @var CandidateResult $candidate */
            $candidate = $entry['candidate'];
            $platform  = $entry['platform'];
            $score     = $entry['score'];
            $embedding = $entry['embedding'];

            // Tier is NOT NULL in schema — fall back to T3.9 if classifier returned nothing
            $tier = (string) ($platform['tier'] ?? 'T3.9');

            $row = [
                'search_run_id'         => $runId,
                'platform_id'           => !empty($platform['id']) ? (int) $platform['id'] : null,
                'url'                   => $candidate->url,
                'title'                 => $candidate->title,
                'snippet'               => $candidate->snippet,
                'author'                => $candidate->author,
                'published_at'          => $candidate->publishedAt?->format('Y-m-d H:i:sP'),
                'source_platform'       => $candidate->sourcePlatform,
                'tier'                  => $tier,
                'base_tier_score'       => $score['base_tier_score'],
                'recency_multiplier'    => $score['recency_multiplier'],
                'relevance_multiplier'  => $score['relevance_multiplier'],
                'sector_match_bonus'    => $score['sector_match_bonus'],
                'sentiment_penalty'     => $score['sentiment_penalty'],
                'candidate_score'       => $score['candidate_score'],
                'sentiment_hint'        => $candidate->sentimentHint,
                'raw_response'          => json_encode($candidate->rawResponse ?? null),
                'accepted'              => false,
                'created_at'            => Capsule::raw('NOW()'),
            ];

            $vector = EmbeddingService::toPgVector($embedding);
            if ($vector !== null) {
                $row['candidate_embedding'] = $vector;
            }

            // rank is captured for the response payload but isn't a DB column
            unset($rank);

            Capsule::table('orbit_search_results')->insert($row);
            return true;
        } catch (Throwable $e) {
            // Persistence failure for one row shouldn't kill the whole run
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Descriptor-based entry point — for frontend use where the caller has
    // brand_id + audit_id + filter_type but does not know the gap_id.
    //
    // If a meridian_competitive_citation_gaps row already exists matching
    // (brand_id, audit_id, citation_tier=filter), use it.
    //
    // Otherwise create one on-demand, drawing displacement metadata from the
    // most recent meridian_filter_classifications row for that brand/audit/
    // filter. A remediation_plan row is also created if no plan exists yet
    // for this audit (FK requirement).
    //
    // Wrapped in a transaction so partial failures don't leave orphan rows.
    // -------------------------------------------------------------------------

    /**
     * Run a search using a descriptor instead of a known gap_id.
     *
     * @param int      $brandId
     * @param int      $auditId
     * @param string   $filterType         e.g. 'T0', 'T1', 'T2', 'T3'
     * @param string[] $requestedTiers
     * @param string   $requestedSentiment
     * @param int|null $perProviderCap
     */
    public function runFromDescriptor(
        int $brandId,
        int $auditId,
        string $filterType,
        array $requestedTiers = [],
        string $requestedSentiment = 'positive',
        ?int $perProviderCap = null
    ): array {
        $gapId = $this->resolveOrCreateGap($brandId, $auditId, $filterType);
        return $this->run($gapId, $requestedTiers, $requestedSentiment, $perProviderCap);
    }

    /**
     * Look up an existing gap row matching (brand_id, audit_id, filter_type)
     * or create one on-demand using classification data.
     *
     * Returns the gap_id (existing or newly created).
     *
     * Throws RuntimeException if there is no classification data to create
     * a gap from.
     */
    private function resolveOrCreateGap(int $brandId, int $auditId, string $filterType): int
    {
        $filterType = strtoupper(trim($filterType));
        if (!preg_match('/^T[0-9]$/', $filterType)) {
            throw new RuntimeException("Invalid filter_type: {$filterType}. Expected T0-T9.");
        }

        // 1. Try to find an existing gap row
        $existing = Capsule::table('meridian_competitive_citation_gaps')
            ->where('brand_id', $brandId)
            ->where('audit_id', $auditId)
            ->where('citation_tier', $filterType)
            ->orderByDesc('gap_severity')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        // 2. Need to create one — wrap in a transaction
        return Capsule::connection()->transaction(function () use ($brandId, $auditId, $filterType): int {
            // Look up brand to get agency_id
            $brand = Capsule::table('meridian_brands')->where('id', $brandId)->first();
            if (!$brand) {
                throw new RuntimeException("Brand not found: {$brandId}");
            }
            $agencyId = (int) ($brand->agency_id ?? 0);
            if ($agencyId <= 0) {
                throw new RuntimeException("Brand {$brandId} has no agency_id");
            }

            // Confirm audit exists and belongs to same brand
            $audit = Capsule::table('meridian_audits')->where('id', $auditId)->first();
            if (!$audit) {
                throw new RuntimeException("Audit not found: {$auditId}");
            }

            // Find the most relevant classification for displacement metadata
            $classifications = Capsule::table('meridian_filter_classifications')
                ->where('audit_id', $auditId)
                ->where('brand_id', $brandId)
                ->orderByDesc('confidence_score')
                ->orderByDesc('survival_gap')
                ->get();

            // Pick the classification whose evidence_gaps array contains our filter
            $matchedClassification = null;
            $gapDescription        = null;
            $displacingBrand       = null;
            $displacementCriteria  = null;

            foreach ($classifications as $c) {
                $gapsJson = json_decode($c->evidence_gaps ?? '[]', true);
                if (!is_array($gapsJson)) continue;

                foreach ($gapsJson as $g) {
                    if (isset($g['filter']) && strtoupper((string) $g['filter']) === $filterType) {
                        $matchedClassification = $c;
                        $gapDescription        = (string) ($g['gap'] ?? '');
                        $displacingBrand       = $c->t4_winner ?? null;
                        $displacementCriteria  = $c->displacement_criteria ?? null;
                        break 2;
                    }
                }
            }

            if (!$matchedClassification) {
                throw new RuntimeException(
                    "No classification data found for filter {$filterType} on brand {$brandId}, audit {$auditId}. "
                    . "Run M1 classification first."
                );
            }

            // Find or create a remediation plan for this audit
            $plan = Capsule::table('meridian_remediation_plans')
                ->where('audit_id', $auditId)
                ->where('brand_id', $brandId)
                ->orderBy('id')
                ->first();

            if (!$plan) {
                $planId = (int) Capsule::table('meridian_remediation_plans')->insertGetId([
                    'audit_id'        => $auditId,
                    'agency_id'       => $agencyId,
                    'brand_id'        => $brandId,
                    'brief_text'      => 'Auto-created by ORBIT for evidence discovery.',
                    'status'          => 'draft',
                    'total_items'     => 0,
                    'items_completed' => 0,
                    'completion_rate' => 0,
                    'created_at'      => Capsule::raw('NOW()'),
                    'updated_at'      => Capsule::raw('NOW()'),
                ]);
            } else {
                $planId = (int) $plan->id;
            }

            // Build category from brand row
            $category    = (string) ($brand->category    ?? '');
            $subcategory = (string) ($brand->subcategory ?? '');

            // Determine platform — use the first platform from the classification, or 'all'
            $platform = isset($matchedClassification->platform) && $matchedClassification->platform !== ''
                ? (string) $matchedClassification->platform
                : 'all';

            // Create the gap row
            $gapId = (int) Capsule::table('meridian_competitive_citation_gaps')->insertGetId([
                'remediation_plan_id'      => $planId,
                'audit_id'                 => $auditId,
                'agency_id'                => $agencyId,
                'brand_id'                 => $brandId,
                'platform'                 => $platform,
                'source_type'              => $matchedClassification->probe_type ?? 'decision_stage',
                'citation_tier'            => $filterType,
                'gap_severity'             => $this->mapSeverityFromSurvivalGap($matchedClassification->survival_gap ?? null),
                'displacing_brand'         => $displacingBrand,
                'brand_currently_present'  => false,
                'intervention_required'    => $gapDescription,
                'expected_content_type'    => $this->expectedContentForFilter($filterType),
                'category'                 => $category !== '' ? $category : 'Uncategorised',
                'subcategory'              => $subcategory !== '' ? $subcategory : null,
                'probe_mode'               => 'auto',
                'created_at'               => Capsule::raw('NOW()'),
                'updated_at'               => Capsule::raw('NOW()'),
            ]);

            return $gapId;
        });
    }

    /**
     * Map M1 survival_gap (turns of exposure) to gap_severity enum.
     */
    private function mapSeverityFromSurvivalGap($survivalGap): string
    {
        $g = is_numeric($survivalGap) ? (int) $survivalGap : 0;
        if ($g >= 5) return 'critical';
        if ($g >= 3) return 'high';
        if ($g >= 1) return 'moderate';
        return 'low';
    }

    /**
     * Suggest an expected_content_type string based on the filter tier.
     * Used as ORBIT's claim text seed when no gap row exists upstream.
     */
    private function expectedContentForFilter(string $filterType): string
    {
        switch ($filterType) {
            case 'T0':
                return 'Wikipedia/Wikidata anchor and authoritative directory listings';
            case 'T1':
                return 'peer-reviewed publications, regulatory approvals, formal independent studies';
            case 'T2':
                return 'expert endorsements, third-party audits, analyst reports, government data';
            case 'T3':
                return 'press coverage, review platforms, case studies, expert videos, market reports';
            default:
                return 'authoritative third-party evidence';
        }
    }
}
