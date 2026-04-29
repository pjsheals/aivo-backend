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
 *   6. Apply pre-scoring filters (retracted papers, non-Latin script).
 *   7. Embed all candidate snippets in one batch (one OpenAI call).
 *   8. Score each candidate via CandidateScorer (with brand-mention check).
 *   9. Persist orbit_search_runs + orbit_search_results.
 *  10. Build evidence_guidance for the user (what's missing, what to commission).
 *  11. Return ranked list + guidance to caller.
 *
 * Stage 9 changes (29 Apr 2026 evening):
 *   - Brand-locked query: brand name wrapped in quotes for exact-phrase matching
 *   - Retracted paper filter: drops [RETRACTED]/(RETRACTED)/WITHDRAWN candidates
 *   - Non-Latin script filter: drops Cyrillic/CJK/Arabic/Greek/Hebrew titles
 *   - Brand-mention scoring: candidates without the brand named get heavy penalty
 *   - Evidence guidance: structured "what to commission" recommendations when
 *     results are zero or partial, so the user knows what evidence to create
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
     *   filtered_retracted: int,
     *   filtered_non_english: int,
     *   filtered_no_brand_match: int,
     *   evidence_guidance: array,
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

        // 6. Pre-scoring filters: retracted papers + non-Latin script.
        //
        // These run BEFORE embedding to save OpenAI calls — no point embedding
        // a retracted spam paper or a Russian-language result we'll discard.
        // Brand-mention check happens during scoring (needs the candidate
        // embedding cosine for the score breakdown).
        $filteredRetracted    = 0;
        $filteredNonEnglish   = 0;
        $beforeFilters        = $allCandidates;
        $allCandidates        = [];
        foreach ($beforeFilters as $entry) {
            /** @var CandidateResult $cand */
            $cand = $entry['candidate'];

            if ($this->isRetracted((string) ($cand->title ?? ''))) {
                $filteredRetracted++;
                continue;
            }

            // Combine title + snippet for script detection — title alone may be
            // an English transliteration of a non-English paper.
            $textForScript = trim(((string) ($cand->title ?? '')) . ' ' . ((string) ($cand->snippet ?? '')));
            if ($this->hasNonLatinScript($textForScript)) {
                $filteredNonEnglish++;
                continue;
            }

            $allCandidates[] = $entry;
        }

        // 7. Batch-embed all candidate texts (post-filter)
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

        // 8. Score each candidate. Pass brandName so scorer can apply
        //    brand-mention penalty for third-party results that don't name it.
        $scored = [];
        $filteredNoBrandMatch = 0;
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
                $requestedSentiment,
                $brandName
            );

            // Track candidates that failed the brand-mention check — for response
            // counts and evidence_guidance. Uses the brand_matched flag directly
            // rather than inferring from score, so we count accurately even when
            // a brand-failed candidate retains some score from sector bonus.
            if (isset($scoreBundle['brand_matched']) && $scoreBundle['brand_matched'] === false) {
                $filteredNoBrandMatch++;
            }

            $scored[] = [
                'candidate'    => $candidate,
                'platform'     => $platform,
                'provider'     => $providerName,
                'embedding'    => $candEmb,
                'score'        => $scoreBundle,
            ];
        }

        // Sort descending by candidate_score
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

        // 10. Build evidence_guidance — tells the user what's missing and what
        //     to commission. Always present in the response so the frontend can
        //     render it consistently across zero-result, partial-result, and
        //     full-result scenarios.
        $evidenceGuidance = $this->buildEvidenceGuidance(
            $gap,
            $brandName,
            $brandCategory,
            $displacementCriteria,
            $scored
        );

        // 11. Return summary
        return [
            'run_id'                  => $runId,
            'gap_id'                  => $gapId,
            'brand_name'              => $brandName,
            'claim'                   => $claimText,
            'query_string'            => $queryString,
            'platforms_searched'      => $platformsSearched,
            'platforms_skipped'       => $platformsSkipped,
            'candidates_total'        => count($allCandidates),
            'candidates_persisted'    => $persisted,
            'filtered_retracted'      => $filteredRetracted,
            'filtered_non_english'    => $filteredNonEnglish,
            'filtered_no_brand_match' => $filteredNoBrandMatch,
            'latency_ms'              => $latencyMs,
            'errors'                  => $errors,
            'evidence_guidance'       => $evidenceGuidance,
            'results'                 => array_map(function ($entry, $rank) {
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
    // Helpers — claim text + query construction
    // -------------------------------------------------------------------------

    /**
     * Build the claim text used for embedding (semantic relevance comparison).
     *
     * The claim must be a POSITIVE statement of what evidence we want to find,
     * NOT a negative description of what's missing. Embedding a negative
     * (e.g. "lacks peer-reviewed evidence") makes ORBIT match papers that are
     * literally about "lacking peer-reviewed publications".
     *
     * Construction priority:
     *   1. displacement_criteria — the actual question the AI asked at T4.
     *   2. brand + category — topical anchors so embeddings stay on-domain.
     *   3. displacing_brand — used as a positive comparator.
     */
    private function buildClaimText(array $gap, string $brandName, string $displacementCriteria = ''): string
    {
        $parts = [];

        $criteria = trim($displacementCriteria);
        if ($criteria !== '') {
            $criteria = rtrim($criteria, " \t\n\r\0\x0B?");
            $parts[] = "Evidence required: " . $criteria . '.';
        } elseif (!empty($gap['claim'])) {
            $parts[] = (string) $gap['claim'];
        }

        // Topical anchors — brand + category keep the embedding on-domain
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

        if (!empty($gap['displacing_brand'])) {
            $parts[] = "Looking for evidence comparable to that supporting "
                    . (string) $gap['displacing_brand'] . ".";
        }

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
     * Stage 9 change: the brand name is wrapped in DOUBLE QUOTES so search APIs
     * treat it as a required exact phrase. This dramatically reduces noise:
     * Brave, PubMed, Crossref, OpenAlex etc. all support phrase quoting.
     *
     * Examples:
     *   brand = "Revitalift Paris"
     *   →  query = '"Revitalift Paris" Beauty Skincare hyaluronic acid clinical
     *              anti-aging measurable results'
     *
     * Skipping the quotes for single-token brand names (e.g. "Microsoft") is
     * unnecessary — most APIs treat unquoted single tokens as exact-match anyway,
     * but quoting is harmless for them.
     */
    private function buildQueryString(array $gap, string $brandName, string $displacementCriteria = ''): string
    {
        $bits = [];

        // Brand goes FIRST as the dominant required phrase. Quote-wrapping
        // tells search APIs to require the literal sequence of tokens.
        if ($brandName !== '') {
            $cleanedBrand = $this->cleanForQuery($brandName);
            if ($cleanedBrand !== '') {
                $bits[] = '"' . $cleanedBrand . '"';
            }
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
     * Strip parens, quotes (single quote and backtick — NOT double quotes,
     * we use those ourselves), and leading articles for cleaner query tokens.
     */
    private function cleanForQuery(string $s): string
    {
        $s = preg_replace('/[\(\)\[\]\'`]+/', ' ', $s) ?? $s;
        $s = preg_replace('/^\s*(the|a|an)\s+/i', '', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * Extract content keywords from a natural-language sentence.
     *
     * Strategy: drop stopwords, drop punctuation, drop very short words,
     * keep the rest in original order. Cap at 12 keywords to keep search
     * APIs happy.
     */
    private function extractContentKeywords(string $text): string
    {
        static $stopwords = [
            'a','an','and','are','as','at','be','been','being','but','by','can','do',
            'does','did','for','from','had','has','have','having','how','if','in',
            'into','is','it','its','of','on','or','our','out','over','than','that',
            'the','their','these','they','this','those','to','was','were','what',
            'when','where','which','who','whom','why','will','with','would','you',
            'your','my','i','we','us','me','him','her','he','she',
            'demonstrate','demonstrating','show','showing','provide','providing',
            'find','finding','need','needs','needed','want','wants','wanted',
            'use','used','using','make','making','made','take','taken','taking',
            'thing','things','way','ways','time','times','case','cases','example',
            'examples','specific','specifically','recent','retrievable','most',
            'comparable','comparison','comparing','versus','vs',
            'brand','brands',
        ];

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
            if (isset($seen[$tok])) continue;
            $seen[$tok] = true;
            $kept[] = $tok;
            if (count($kept) >= 12) break;
        }

        return implode(' ', $kept);
    }

    // -------------------------------------------------------------------------
    // Helpers — pre-scoring filters (Stage 9)
    // -------------------------------------------------------------------------

    /**
     * Detect retracted publications from common title markers.
     *
     * Crossref and PubMed prefix retracted papers with [RETRACTED] or
     * (RETRACTED) in the title. Withdrawn preprints on arXiv use WITHDRAWN:.
     * Various journals use "Retraction:" as a prefix. We catch all variants
     * with one case-insensitive regex.
     *
     * False-positive safety: the markers must appear at the start of the title
     * or as a standalone bracketed token, so a sentence merely *mentioning*
     * "retraction" in a body of text won't match. (Snippets aren't checked
     * for this reason — they often contain prose discussion of retractions.)
     */
    private function isRetracted(string $title): bool
    {
        if ($title === '') return false;
        $trim = trim($title);

        // Bracketed markers anywhere in the title:
        //   [RETRACTED] ... / (RETRACTED) ... / [Retracted Article] ...
        if (preg_match('/[\[\(]\s*retract(ed|ion)\b/i', $trim) === 1) {
            return true;
        }

        // Leading prefixes:
        //   RETRACTED: ... / WITHDRAWN: ... / Retraction: ... / Retraction notice ...
        if (preg_match('/^\s*(retracted?|retraction(?:\s+notice)?|withdrawn)\s*[:\-]/i', $trim) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Detect predominantly non-Latin-script text (Cyrillic, CJK, Arabic, Greek,
     * Hebrew, Thai, Devanagari, etc).
     *
     * Strategy: count characters that are letters (\p{L}) and check what fraction
     * are Latin script. If less than 50% Latin and the text has any meaningful
     * length, consider it non-English. Title+snippet combined is a strong signal.
     *
     * Tuning notes:
     *   - 50% threshold is forgiving — a paper with a Russian title but English
     *     abstract snippet would still pass if the snippet is sufficiently long.
     *   - We deliberately do NOT use a language-detection library for now —
     *     simple script counting handles 95% of the noise we saw in the
     *     Revitalift run. If a brand needs Russian/Chinese coverage later
     *     we'll plumb language preferences through from the brand row.
     */
    private function hasNonLatinScript(string $text): bool
    {
        if ($text === '') return false;

        // Quick pre-check: any non-ASCII letters at all?
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            // Pure ASCII → definitely Latin → not non-Latin
            return false;
        }

        // Count letter characters by script. \p{L} = any letter.
        $allLetters = preg_match_all('/\p{L}/u', $text, $m);
        if ($allLetters === false || $allLetters === 0) {
            return false;
        }

        $latinLetters = preg_match_all('/\p{Latin}/u', $text);
        if ($latinLetters === false) {
            return false;
        }

        // Ignore short fragments — single-word non-Latin terms inside an
        // otherwise English sentence shouldn't disqualify the candidate.
        if ($allLetters < 20) {
            return false;
        }

        $latinFraction = $latinLetters / $allLetters;
        return $latinFraction < 0.5;
    }

    // -------------------------------------------------------------------------
    // Helpers — evidence guidance (Stage 9)
    // -------------------------------------------------------------------------

    /**
     * Build structured guidance for the user describing what evidence is
     * needed to close this gap. Always returned, regardless of result count,
     * so the frontend can render consistently:
     *
     *   - status='no_results'   → 0 candidates returned, full commission list
     *   - status='partial'      → some results but missing critical tiers
     *   - status='sufficient'   → strong T1/T2 stack present
     *
     * Recommendations are templated by tier (T0/T1/T2/T3) with brand,
     * category, and displacement criteria substituted in.
     *
     * @param array<string,mixed> $gap
     * @param array<int,array>    $scored
     */
    private function buildEvidenceGuidance(
        array $gap,
        string $brandName,
        string $brandCategory,
        string $displacementCriteria,
        array $scored
    ): array {
        $tier = strtoupper((string) ($gap['citation_tier'] ?? ''));
        $tier = preg_match('/^T[0-9]$/', $tier) === 1 ? $tier : 'T1';

        $displacingBrand = (string) ($gap['displacing_brand'] ?? '');
        $gapSeverity     = (string) ($gap['gap_severity']     ?? 'moderate');

        // Effective candidate count = candidates with brand_matched=true AND
        // candidate_score >= 5.0. The brand_matched check filters out candidates
        // penalised purely for not naming the brand; the score threshold filters
        // out candidates with poor relevance or low-tier authority. 5.0 is
        // calibrated empirically: a T3 brand-matched candidate with mediocre
        // relevance scores ~19; a brand-failed T1 with high topical relevance
        // scores ~5.6. The 5.0 threshold cleanly separates them.
        $effective = 0;
        $tiersFound = [];
        foreach ($scored as $entry) {
            $score = (float) ($entry['score']['candidate_score'] ?? 0.0);
            $brandMatched = (bool) ($entry['score']['brand_matched'] ?? true);
            if ($brandMatched && $score >= 5.0) {
                $effective++;
                $foundTier = (string) ($entry['platform']['tier'] ?? '');
                if ($foundTier !== '') {
                    $major = strtoupper(substr($foundTier, 0, 2));
                    if (in_array($major, ['T1', 'T2', 'T3'], true)) {
                        $tiersFound[$major] = ($tiersFound[$major] ?? 0) + 1;
                    }
                }
            }
        }

        // Status: zero / partial / sufficient
        if ($effective === 0) {
            $status = 'no_results';
        } elseif (!isset($tiersFound['T1']) && in_array($tier, ['T0', 'T1'], true)) {
            // T0/T1 gaps need T1-or-higher evidence to be considered sufficient
            $status = 'partial';
        } elseif ($effective < 2) {
            // Even with one match, a single source is rarely sufficient on its own
            $status = 'partial';
        } else {
            $status = 'sufficient';
        }

        // Why no results — diagnostic explanation tailored to the situation
        $whyNoResults = $this->buildWhyNoResults($status, $brandName, $displacingBrand, $tier, $effective);

        // Build the commission recommendations specific to this tier
        $recommendations = $this->buildCommissionList(
            $tier,
            $brandName,
            $brandCategory,
            $displacementCriteria,
            $displacingBrand
        );

        return [
            'status'                => $status,
            'tier_required'         => $tier,
            'gap_severity'          => $gapSeverity,
            'displacement_criteria' => $displacementCriteria,
            'displacing_brand'      => $displacingBrand !== '' ? $displacingBrand : null,
            'effective_results'     => $effective,
            'tiers_found'           => $tiersFound,
            'why_no_results'        => $whyNoResults,
            'what_to_commission'    => $recommendations,
        ];
    }

    /**
     * Compose a human-readable diagnostic explaining why ORBIT didn't return
     * sufficient evidence. Tailored to the actual scenario.
     */
    private function buildWhyNoResults(
        string $status,
        string $brandName,
        string $displacingBrand,
        string $tier,
        int $effective
    ): string {
        if ($status === 'sufficient') {
            return sprintf(
                'ORBIT found %d candidate%s naming %s with tier coverage adequate for this gap. Review the ranked list above and select the strongest evidence for your atom.',
                $effective,
                $effective === 1 ? '' : 's',
                $brandName !== '' ? $brandName : 'this brand'
            );
        }

        $brandLabel = $brandName !== '' ? $brandName : 'this brand';

        if ($status === 'no_results') {
            $base = sprintf(
                'No third-party content was found that names %s in connection with this displacement criteria.',
                $brandLabel
            );

            if ($displacingBrand !== '') {
                $base .= sprintf(
                    ' The displacing brand (%s) currently holds this turn because it has retrievable %s evidence; %s does not.',
                    $displacingBrand,
                    $tier,
                    $brandLabel
                );
            } else {
                $base .= ' This means the brand has no retrievable third-party authority on this specific question.';
            }

            $base .= ' To win this turn, you need to commission new evidence — see the recommendations below.';

            return $base;
        }

        // Partial
        return sprintf(
            'ORBIT found %d candidate%s, but the evidence stack is incomplete for a %s gap. Review the recommendations below for what to commission to strengthen this gap.',
            $effective,
            $effective === 1 ? '' : 's',
            $tier
        );
    }

    /**
     * Build the tier-specific list of evidence to commission. Each
     * recommendation has type, priority, description, specificity_required,
     * and estimated_effort.
     *
     * Templates are deliberately concrete: they reference the brand name,
     * category, and displacement criteria so the user gets actionable text
     * rather than generic advice.
     */
    private function buildCommissionList(
        string $tier,
        string $brandName,
        string $brandCategory,
        string $displacementCriteria,
        string $displacingBrand
    ): array {
        $brandLabel = $brandName !== '' ? $brandName : 'the brand';
        $categoryLabel = $brandCategory !== '' ? $brandCategory : 'this category';
        $criteriaShort = trim(rtrim($displacementCriteria, ' ?.')) ?: 'the displacement criteria above';

        $competitorClause = $displacingBrand !== ''
            ? sprintf(' Match or exceed the evidential weight currently held by %s.', $displacingBrand)
            : '';

        switch ($tier) {
            case 'T0':
                return [
                    [
                        'priority'             => 1,
                        'type'                 => 'wikipedia_article',
                        'description'          => sprintf(
                            'A Wikipedia article for %s as a notable entity in %s. This is the foundational T0 anchor — without it, AI models have no canonical entity to attach evidence to.',
                            $brandLabel,
                            $categoryLabel
                        ),
                        'specificity_required' => 'Must meet Wikipedia notability standards: substantive third-party coverage, not promotional, with citations to T1/T2 sources. Submit via your own AIVO Standard account with COI disclosure.',
                        'estimated_effort'     => 'Medium — depends on whether sufficient T1/T2 evidence already exists to satisfy notability. Without that, build T1/T2 evidence first.',
                    ],
                    [
                        'priority'             => 2,
                        'type'                 => 'wikidata_entry',
                        'description'          => sprintf(
                            'A Wikidata entity (Q-number) for %s linking parent company, product line, category, and any sub-brands. Enables structured retrieval by AI agents.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Properties: instance-of (commercial product/brand), parent-organisation, industry/category, official website, and external IDs (LinkedIn, Crunchbase, etc.) where available.',
                        'estimated_effort'     => 'Low — can usually be created in 1-2 hours once Wikipedia article is approved.',
                    ],
                    [
                        'priority'             => 3,
                        'type'                 => 'authoritative_directory_listing',
                        'description'          => sprintf(
                            'A listing in an authoritative %s directory (G2, Crunchbase, Capterra, or category-specific equivalents like INCIDecoder for cosmetics, Drugs.com for pharma).',
                            $categoryLabel
                        ),
                        'specificity_required' => 'Verified profile with company description, category tags, and at least 5-10 substantive verified reviews or data points.',
                        'estimated_effort'     => 'Low — most directories accept verified company submissions; ratings build over time.',
                    ],
                ];

            case 'T1':
                return [
                    [
                        'priority'             => 1,
                        'type'                 => 'peer_reviewed_publication',
                        'description'          => sprintf(
                            'A peer-reviewed publication or formal independent study naming %s and addressing: "%s"',
                            $brandLabel,
                            $criteriaShort
                        ) . $competitorClause,
                        'specificity_required' => sprintf(
                            'Must name %s explicitly (not just the ingredient/technology category), include subject count, duration, measurement methodology, and specific quantified results. Target a journal indexed in PubMed, Crossref, or OpenAlex.',
                            $brandLabel
                        ),
                        'estimated_effort'     => 'High — typically 6-18 months to commission and publish a clinical study or formal trial. Faster path: surface existing internal R&D data via Zenodo with a DOI (~2 weeks).',
                    ],
                    [
                        'priority'             => 2,
                        'type'                 => 'regulatory_filing',
                        'description'          => sprintf(
                            'A regulatory filing referencing %s and the specific claim above. For consumer brands: EU CPNP product notification, FDA OTC documentation, or equivalent. For pharma: FDA/EMA approvals. For B2B: SEC filings citing the capability.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Notification reference number, year, regulatory body, and a public URL where the filing is searchable.',
                        'estimated_effort'     => 'Low-medium — likely already exists internally; requires legal/regulatory team to surface publicly with a citable reference.',
                    ],
                    [
                        'priority'             => 3,
                        'type'                 => 'expert_endorsement_with_evidence',
                        'description'          => sprintf(
                            'A recognised expert (consultant, professor, or category authority) publicly endorsing %s with specific reference to the underlying clinical, technical, or empirical evidence — not a generic recommendation.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Named expert with credentials and institutional affiliation. The endorsement must reference the specific data and be published on a citable platform (journal, professional body, or recognised media outlet).',
                        'estimated_effort'     => 'Medium — requires expert identification, briefing, and securing publication channel. 2-3 months realistic.',
                    ],
                    [
                        'priority'             => 4,
                        'type'                 => 'wikipedia_anchor',
                        'description'          => sprintf(
                            'A Wikipedia article or substantial section for %s citing the T1 evidence above.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Wikipedia notability standards must be met first — the T1 evidence above is the prerequisite, not the deliverable.',
                        'estimated_effort'     => 'Low once the T1 evidence exists; impossible without it.',
                    ],
                ];

            case 'T2':
                return [
                    [
                        'priority'             => 1,
                        'type'                 => 'analyst_report',
                        'description'          => sprintf(
                            'An independent analyst report or industry audit naming %s in the context of %s. Examples: Forrester Wave, Gartner Magic Quadrant, McKinsey/Bain industry reports, Mintel/Euromonitor category research.',
                            $brandLabel,
                            $categoryLabel
                        ) . $competitorClause,
                        'specificity_required' => sprintf(
                            'Must name %s explicitly, with quantified positioning (rating, market share, capability score) and methodology disclosure. Pulled quotes alone are not sufficient — the report must cite the brand by name in its findings.',
                            $brandLabel
                        ),
                        'estimated_effort'     => 'High — most major analyst inclusion requires a formal vendor briefing process. 6-12 month timeline. Faster path: commission a category-specific independent audit.',
                    ],
                    [
                        'priority'             => 2,
                        'type'                 => 'government_data_citation',
                        'description'          => sprintf(
                            'A citation in government or regulatory body data — gov.uk, US federal data, EU regulatory database — that names %s in the context of the displacement criteria.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Direct mention of the brand in a publicly accessible government/regulatory dataset, ideally linkable to a specific reference number or URL.',
                        'estimated_effort'     => 'Variable — depends on category. Pharma/finance/insurance brands typically have these by default; consumer brands rarely do.',
                    ],
                    [
                        'priority'             => 3,
                        'type'                 => 'standards_certification',
                        'description'          => sprintf(
                            'Certification or compliance with an industry standards body relevant to %s.',
                            $categoryLabel
                        ),
                        'specificity_required' => 'Verifiable certification with a public registry entry, issue date, and scope. Self-asserted compliance does not count.',
                        'estimated_effort'     => 'Medium — depends on whether existing certifications can be surfaced or new ones need to be obtained. 3-6 months.',
                    ],
                    [
                        'priority'             => 4,
                        'type'                 => 'expert_credentials',
                        'description'          => 'Named experts associated with the brand (CTO, Chief Scientist, Head of Research) with verifiable credentials and publications relevant to the displacement criteria.',
                        'specificity_required' => 'LinkedIn profile, ORCID record, or institutional bio listing publications, patents, or recognised contributions in the relevant field.',
                        'estimated_effort'     => 'Low — usually exists internally; requires surfacing publicly via LinkedIn, ORCID, or company-published bios.',
                    ],
                ];

            case 'T3':
                return [
                    [
                        'priority'             => 1,
                        'type'                 => 'category_press_coverage',
                        'description'          => sprintf(
                            'Recent (last 12 months) press coverage of %s in %s trade press. For beauty: Allure, Vogue Business, WWD, Cosmetics Business. For finance: FT, Bloomberg, Reuters. Adapt to the brand category.',
                            $brandLabel,
                            $categoryLabel
                        ),
                        'specificity_required' => sprintf(
                            'Coverage must name %s and address the displacement criteria substantively — not a passing mention or product launch announcement.',
                            $brandLabel
                        ),
                        'estimated_effort'     => 'Medium — requires PR outreach with a substantive story angle. 1-3 months.',
                    ],
                    [
                        'priority'             => 2,
                        'type'                 => 'expert_review_quantified',
                        'description'          => sprintf(
                            'An independent expert review (dermatologist, analyst, recognised practitioner) of %s with quantified findings or measurable assessment.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Expert with verifiable credentials, review must include specific measurable observations (not just "I recommend it"), published on a recognised platform.',
                        'estimated_effort'     => 'Medium — requires identifying credible experts and securing review.',
                    ],
                    [
                        'priority'             => 3,
                        'type'                 => 'recent_case_study',
                        'description'          => sprintf(
                            'A recent (last 12 months) case study or customer evidence demonstrating %s in use, with measurable outcomes.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Named customer (or anonymised with sector/scale), specific quantified outcomes, published on a citable platform (own site is acceptable for T3 but third-party hosted is stronger).',
                        'estimated_effort'     => 'Low-medium — most brands have customer evidence internally; requires customer permission and publication.',
                    ],
                    [
                        'priority'             => 4,
                        'type'                 => 'review_platform_listing',
                        'description'          => sprintf(
                            'Verified ratings on category review platforms relevant to %s.',
                            $categoryLabel
                        ),
                        'specificity_required' => 'At least 20+ verified reviews on a recognised platform, with average rating above 4.0. Self-reported testimonials do not count.',
                        'estimated_effort'     => 'Variable — ratings build over time; can be accelerated with verified-review campaigns.',
                    ],
                ];

            default:
                return [
                    [
                        'priority'             => 1,
                        'type'                 => 'authoritative_third_party',
                        'description'          => sprintf(
                            'Authoritative third-party evidence naming %s and addressing the displacement criteria above.',
                            $brandLabel
                        ),
                        'specificity_required' => 'Recognised independent source with editorial standards.',
                        'estimated_effort'     => 'Variable.',
                    ],
                ];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers — provider construction + persistence
    // -------------------------------------------------------------------------

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

        // query_string is not a column in the schema — kept in the response only
        unset($queryString);

        return (int) Capsule::table('orbit_search_runs')->insertGetId($payload);
    }

    private function commaSeparatedQuoted(array $values): string
    {
        $parts = [];
        foreach ($values as $v) {
            $s = (string) $v;
            $s = str_replace("'", "''", $s);
            $parts[] = "'{$s}'";
        }
        return implode(',', $parts);
    }

    private function persistResult(int $runId, int $rank, array $entry): bool
    {
        try {
            /** @var CandidateResult $candidate */
            $candidate = $entry['candidate'];
            $platform  = $entry['platform'];
            $score     = $entry['score'];
            $embedding = $entry['embedding'];

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

            unset($rank);

            Capsule::table('orbit_search_results')->insert($row);
            return true;
        } catch (Throwable $e) {
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
     */
    private function resolveOrCreateGap(int $brandId, int $auditId, string $filterType): int
    {
        $filterType = strtoupper(trim($filterType));
        if (!preg_match('/^T[0-9]$/', $filterType)) {
            throw new RuntimeException("Invalid filter_type: {$filterType}. Expected T0-T9.");
        }

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

        return Capsule::connection()->transaction(function () use ($brandId, $auditId, $filterType): int {
            $brand = Capsule::table('meridian_brands')->where('id', $brandId)->first();
            if (!$brand) {
                throw new RuntimeException("Brand not found: {$brandId}");
            }
            $agencyId = (int) ($brand->agency_id ?? 0);
            if ($agencyId <= 0) {
                throw new RuntimeException("Brand {$brandId} has no agency_id");
            }

            $audit = Capsule::table('meridian_audits')->where('id', $auditId)->first();
            if (!$audit) {
                throw new RuntimeException("Audit not found: {$auditId}");
            }

            $classifications = Capsule::table('meridian_filter_classifications')
                ->where('audit_id', $auditId)
                ->where('brand_id', $brandId)
                ->orderByDesc('confidence_score')
                ->orderByDesc('survival_gap')
                ->get();

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

            $category    = (string) ($brand->category    ?? '');
            $subcategory = (string) ($brand->subcategory ?? '');

            $platform = isset($matchedClassification->platform) && $matchedClassification->platform !== ''
                ? (string) $matchedClassification->platform
                : 'all';

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

    private function mapSeverityFromSurvivalGap($survivalGap): string
    {
        $g = is_numeric($survivalGap) ? (int) $survivalGap : 0;
        if ($g >= 5) return 'critical';
        if ($g >= 3) return 'high';
        if ($g >= 1) return 'moderate';
        return 'low';
    }

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
