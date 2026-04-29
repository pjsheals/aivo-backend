<?php

declare(strict_types=1);

namespace Aivo\Orbit\Services;

use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Services\EmbeddingService;
use DateTimeImmutable;
use Throwable;

/**
 * CandidateScorer — applies the locked ORBIT scoring formula:
 *
 *   candidate_score = base_tier_score
 *                   * recency_multiplier
 *                   * relevance_multiplier
 *                   * relevance_floor_penalty
 *                   * brand_match_penalty           ← Stage 9
 *                   + sector_match_bonus_points
 *                   - sentiment_penalty
 *
 * All component values are exposed via score(), so the orchestrator can
 * persist them denormalised in orbit_search_results. Historical scores
 * stay reproducible even if citation_platforms.score_base is later edited.
 *
 * Stage 9 changes:
 *   - brand_match_penalty: candidates whose title+snippet do not contain a
 *     distinctive token from the brand name get a heavy multiplicative
 *     penalty. Prevents on-topic-but-wrong-brand academic noise from
 *     dominating results (e.g. Korean skincare papers ranking #1 for a
 *     Revitalift Paris gap because the embedding matched "skincare").
 *
 * Brand-match design:
 *   The brand name is tokenised. Each token is classified as either
 *   "distinctive" (e.g. "Revitalift", "Tilbury") or "generic" (e.g. "Paris",
 *   "Pro", "Plus" — single common words that match too broadly). A candidate
 *   passes the brand-match check if its title or snippet contains:
 *     - the full brand name as a substring, OR
 *     - any individual distinctive token, OR
 *     - any contiguous substring containing at least one distinctive token
 *   Generic tokens alone never qualify.
 */
final class CandidateScorer
{
    /** Recency decay constants */
    private const RECENCY_FRESH_DAYS    = 30;     // <30d → 1.0
    private const RECENCY_DECAY_DAYS    = 1825;   // 5 years → 0.5
    private const RECENCY_FLOOR         = 0.5;    // never below this

    /** Sector match adds this many score points (additive, not multiplicative) */
    private const SECTOR_MATCH_BONUS    = 0.2;    // stored in DB as 0.000-1.000 range

    /** Counter-evidence penalty when claim sentiment is positive */
    private const SENTIMENT_COUNTER_PENALTY = 30.0;

    /** Cosine similarity is 0..1; we map it to 0..1.5 to allow strong matches to amplify */
    private const RELEVANCE_AMPLIFIER   = 1.5;

    /**
     * Relevance floor — results below these cosine thresholds get heavy penalties
     * regardless of tier authority.
     */
    private const RELEVANCE_FLOOR_HARD   = 0.15;  // below this: 95% penalty
    private const RELEVANCE_FLOOR_SOFT   = 0.25;  // below this: 80% penalty
    private const RELEVANCE_PENALTY_HARD = 0.05;
    private const RELEVANCE_PENALTY_SOFT = 0.20;

    /**
     * Brand-match penalty — applied when the candidate title+snippet does NOT
     * contain a distinctive token from the brand name. 95% reduction is
     * deliberately aggressive: if the brand isn't named, the result is
     * almost never useful as evidence FOR that brand.
     */
    private const BRAND_MATCH_PENALTY    = 0.05;

    /**
     * Generic-token stopword list — single-word brand-name fragments that
     * appear too commonly to be useful as standalone brand match signals.
     * "Paris" alone shouldn't count as a Revitalift Paris match. "Pro", "Plus",
     * "Max", "One" alone shouldn't qualify for various tech brand matches.
     *
     * Tokens MUST be lowercase. Match is case-insensitive.
     */
    private const GENERIC_BRAND_TOKENS = [
        // Geographic / location tokens that match too broadly
        'paris', 'london', 'tokyo', 'usa', 'uk', 'us', 'eu', 'global', 'international',
        // Generic product modifiers
        'pro', 'plus', 'max', 'mini', 'one', 'two', 'three', 'lite', 'light',
        'standard', 'basic', 'premium', 'advanced', 'classic', 'original',
        // Generic company-name fragments
        'co', 'inc', 'ltd', 'llc', 'gmbh', 'sa', 'plc', 'corp',
        'group', 'company', 'companies', 'brands', 'industries',
        // Generic descriptors
        'new', 'the', 'and', 'for', 'with',
    ];

    /**
     * Score a candidate against a claim's embedding and the platform metadata.
     *
     * @param CandidateResult $candidate
     * @param array           $platform           Row from citation_platforms (or sensible defaults).
     * @param float[]         $claimEmbedding     Pre-computed claim embedding.
     * @param float[]         $candidateEmbedding Pre-computed candidate embedding.
     * @param string[]        $brandSectors       e.g. ['beauty','cpg'] from brand row.
     * @param string          $requestedSentiment 'positive'|'neutral'|'any'.
     * @param string          $brandName          The brand we're scoring evidence for.
     *                                            Empty string disables brand-match check
     *                                            (back-compat for legacy callers).
     *
     * @return array{
     *   base_tier_score: float,
     *   recency_multiplier: float,
     *   relevance_multiplier: float,
     *   sector_match_bonus: float,
     *   sentiment_penalty: float,
     *   relevance_floor_penalty: float,
     *   brand_match_penalty: float,
     *   candidate_score: float,
     *   relevance_cosine: float,
     *   brand_matched: bool
     * }
     */
    public function score(
        CandidateResult $candidate,
        array $platform,
        array $claimEmbedding,
        array $candidateEmbedding,
        array $brandSectors,
        string $requestedSentiment = 'positive',
        string $brandName = ''
    ): array {
        // 1. base_tier_score — from citation_platforms row
        $baseTierScore = isset($platform['score_base'])
            ? (float) $platform['score_base']
            : 15.0; // T3.9 fallback if no platform classified

        // 2. recency_multiplier
        $recencyMultiplier = $this->recencyMultiplier($candidate->publishedAt);

        // 3. relevance_multiplier — cosine similarity scaled
        $cosine = EmbeddingService::cosineSimilarity($claimEmbedding, $candidateEmbedding);
        $cosine = max(0.0, min(1.0, $cosine));
        $relevanceMultiplier = $cosine * self::RELEVANCE_AMPLIFIER;

        // 4. sector_match_bonus — 0 or +0.2 depending on whether sectors overlap
        $sectorMatchBonus = $this->sectorMatchBonus(
            isset($platform['sector']) && is_array($platform['sector']) ? $platform['sector'] : [],
            $brandSectors
        );

        // 5. sentiment_penalty — applies when claim is positive AND platform is tagged counter-evidence
        $sentimentPenalty = $this->sentimentPenalty(
            isset($platform['tags']) && is_array($platform['tags']) ? $platform['tags'] : [],
            $requestedSentiment,
            $candidate->sentimentHint
        );

        // 6. final composition
        $score = ($baseTierScore * $recencyMultiplier * $relevanceMultiplier);
        // Sector bonus is added AFTER the multiplicative chain so its impact is
        // proportional to the candidate's strength.
        $score += ($sectorMatchBonus * $baseTierScore);
        $score -= $sentimentPenalty;

        // 6b. Relevance floor penalty (cosine too low)
        $relevanceFloorPenalty = 1.0;
        if ($cosine < self::RELEVANCE_FLOOR_HARD) {
            $relevanceFloorPenalty = self::RELEVANCE_PENALTY_HARD;
        } elseif ($cosine < self::RELEVANCE_FLOOR_SOFT) {
            $relevanceFloorPenalty = self::RELEVANCE_PENALTY_SOFT;
        }
        if ($relevanceFloorPenalty < 1.0) {
            $score *= $relevanceFloorPenalty;
        }

        // 6c. Brand-match penalty (Stage 9). Candidates whose title+snippet
        // do NOT contain a distinctive token of the brand name are heavily
        // penalised. This stops on-topic-but-wrong-brand noise.
        $brandMatchPenalty = 1.0;
        $brandMatched      = true; // default-true for back-compat when brandName is empty
        if ($brandName !== '') {
            $haystack = trim(((string) ($candidate->title ?? '')) . ' ' . ((string) ($candidate->snippet ?? '')));
            $brandMatched = $this->isBrandMentioned($haystack, $brandName);
            if (!$brandMatched) {
                $brandMatchPenalty = self::BRAND_MATCH_PENALTY;
                $score *= $brandMatchPenalty;
            }
        }

        // Floor at zero — negative scores are noise
        $score = max(0.0, $score);

        return [
            'base_tier_score'         => round($baseTierScore, 2),
            'recency_multiplier'      => round($recencyMultiplier, 3),
            'relevance_multiplier'    => round($relevanceMultiplier, 3),
            'sector_match_bonus'      => round($sectorMatchBonus, 3),
            'sentiment_penalty'       => round($sentimentPenalty, 2),
            'relevance_floor_penalty' => round($relevanceFloorPenalty, 3),
            'brand_match_penalty'     => round($brandMatchPenalty, 3),
            'brand_matched'           => $brandMatched,
            'candidate_score'         => round($score, 2),
            'relevance_cosine'        => round($cosine, 4),
        ];
    }

    /**
     * Return true if the haystack (title+snippet) contains the brand name in
     * a form that qualifies as a real brand mention.
     *
     * Logic:
     *   1. Tokenise brand name. Lowercase, strip punctuation.
     *   2. If full brand name (joined) appears as a substring → match.
     *   3. Otherwise, check each token: if ANY distinctive token appears as a
     *      whole word, → match.
     *   4. Generic tokens alone (e.g. "Paris", "Pro") never qualify.
     *
     * Examples for brand "Revitalift Paris":
     *   - haystack contains "Revitalift Paris"   → match (full string)
     *   - haystack contains "Revitalift Filler"  → match ("Revitalift" distinctive)
     *   - haystack contains "L'Oreal Revitalift" → match ("Revitalift" distinctive)
     *   - haystack contains "Paris fashion week" → no match (only generic token)
     *   - haystack contains "L'Oreal Paris"      → no match (no distinctive token)
     *   - haystack contains "skincare in Paris"  → no match
     *
     * Edge case: a brand with ONLY generic tokens (rare — "The Plus Company")
     * falls back to substring match of the full name. Tokens-individually fail
     * but the full name as a substring still works.
     */
    private function isBrandMentioned(string $haystack, string $brandName): bool
    {
        if ($haystack === '' || $brandName === '') {
            return false;
        }

        $haystackLower = mb_strtolower($haystack);
        $brandClean    = $this->cleanBrandForMatch($brandName);
        $brandLower    = mb_strtolower($brandClean);

        // 1. Full-brand-name substring match (most permissive)
        if ($brandLower !== '' && mb_strpos($haystackLower, $brandLower) !== false) {
            return true;
        }

        // 2. Tokenise — split on whitespace and hyphens.
        //    Hyphenated brands (e.g. "Coca-Cola") still get checked as full string above;
        //    tokenisation here lets us also match "Coca" or "Cola" individually.
        $tokens = preg_split('/[\s\-]+/u', $brandClean) ?: [];
        $tokens = array_values(array_filter(array_map(static fn ($t) => trim($t), $tokens)));

        $distinctive = array_filter(
            $tokens,
            fn ($t) => $this->isDistinctiveToken($t)
        );

        // 3. Distinctive-token whole-word match
        foreach ($distinctive as $tok) {
            if ($this->containsWholeWord($haystackLower, mb_strtolower($tok))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip common brand-name decorations to improve matching:
     *   - Trademark/copyright marks (™ ® ©)
     *   - Surrounding quotes
     *   - Leading articles (The/A/An)
     *   - Multiple internal whitespace
     * We keep apostrophes and hyphens — they're meaningful in many brand
     * names (e.g. L'Oréal, Coca-Cola).
     */
    private function cleanBrandForMatch(string $brand): string
    {
        $b = trim($brand);
        // Strip trademark/copyright marks
        $b = str_replace(['™', '®', '©', '℠', '"', '"', '\u{201C}', '\u{201D}'], '', $b);
        // Strip wrapping quotes
        $b = trim($b, " \t\n\r\0\x0B\"'`");
        // Strip leading article
        $b = preg_replace('/^\s*(the|a|an)\s+/i', '', $b) ?? $b;
        // Collapse whitespace
        $b = preg_replace('/\s+/', ' ', $b) ?? $b;
        return trim($b);
    }

    /**
     * A token is "distinctive" if:
     *   - It's at least 4 characters long, AND
     *   - It's not in the GENERIC_BRAND_TOKENS list
     *
     * Length rule keeps ultra-short fragments (like "AT", "GE") from being
     * considered distinctive — they collide with English prepositions/words.
     * Brands that ARE short common words (Apple, Meta, Visa) will fall back
     * to the full-string match above, which still works for them.
     */
    private function isDistinctiveToken(string $token): bool
    {
        $t = mb_strtolower(trim($token));
        if ($t === '') return false;
        if (mb_strlen($t) < 4) return false;
        if (in_array($t, self::GENERIC_BRAND_TOKENS, true)) return false;
        return true;
    }

    /**
     * Check whether $needle appears as a whole word inside $haystack.
     * "Whole word" = bordered by non-letter characters on both sides
     * (or string start/end). Prevents "Aple" matching inside "Pineapple".
     *
     * Both arguments must already be lowercased.
     */
    private function containsWholeWord(string $haystackLower, string $needleLower): bool
    {
        if ($needleLower === '') return false;
        // Use \b for word boundary, but \b only works on \w characters in PCRE
        // by default. \w doesn't include accented letters → for "L'Oréal" or
        // "Tetra Pak"-type brands we'd miss accents. Use \p{L} negated lookarounds.
        $escaped = preg_quote($needleLower, '/');
        // Match if not preceded by a letter/number and not followed by one
        $pattern = '/(?<![\p{L}\p{N}])' . $escaped . '(?![\p{L}\p{N}])/u';
        return preg_match($pattern, $haystackLower) === 1;
    }

    /**
     * Linear decay from 1.0 at <30d old, down to 0.5 at 5 years, floor at 0.5.
     */
    private function recencyMultiplier(?DateTimeImmutable $publishedAt): float
    {
        if ($publishedAt === null) {
            return 0.75;
        }

        try {
            $now      = new DateTimeImmutable('now');
            $diffDays = ($now->getTimestamp() - $publishedAt->getTimestamp()) / 86400.0;
        } catch (Throwable) {
            return 0.75;
        }

        if ($diffDays <= self::RECENCY_FRESH_DAYS) {
            return 1.0;
        }
        if ($diffDays >= self::RECENCY_DECAY_DAYS) {
            return self::RECENCY_FLOOR;
        }

        $span     = (float) (self::RECENCY_DECAY_DAYS - self::RECENCY_FRESH_DAYS);
        $progress = (float) ($diffDays - self::RECENCY_FRESH_DAYS) / $span;
        return 1.0 - ($progress * (1.0 - self::RECENCY_FLOOR));
    }

    /**
     * Returns SECTOR_MATCH_BONUS if any platform sector overlaps any brand sector,
     * otherwise 0. Empty sector arrays mean "applies to all" — count as match.
     *
     * @param string[] $platformSectors
     * @param string[] $brandSectors
     */
    private function sectorMatchBonus(array $platformSectors, array $brandSectors): float
    {
        if ($platformSectors === [] || $brandSectors === []) {
            return self::SECTOR_MATCH_BONUS;
        }

        $platformLower = array_map(static fn ($s) => strtolower((string) $s), $platformSectors);
        foreach ($brandSectors as $bs) {
            if (in_array(strtolower((string) $bs), $platformLower, true)) {
                return self::SECTOR_MATCH_BONUS;
            }
        }
        return 0.0;
    }

    /**
     * Penalty when:
     *   - Platform is tagged counter-evidence/fact-check, AND
     *   - Caller asked for positive-sentiment evidence
     *
     * If the candidate's own sentiment_hint is 'negative' or 'counter', also penalise
     * regardless of platform tags.
     *
     * @param string[] $platformTags
     */
    private function sentimentPenalty(
        array $platformTags,
        string $requestedSentiment,
        ?string $candidateSentimentHint
    ): float {
        if ($requestedSentiment !== 'positive') {
            return 0.0;
        }

        $tagsLower = array_map(static fn ($t) => strtolower((string) $t), $platformTags);
        $isCounterPlatform = in_array('counter-evidence', $tagsLower, true)
                          || in_array('fact-check', $tagsLower, true);

        if ($isCounterPlatform) {
            return self::SENTIMENT_COUNTER_PENALTY;
        }

        if ($candidateSentimentHint !== null) {
            $hint = strtolower($candidateSentimentHint);
            if ($hint === 'negative' || $hint === 'counter') {
                return self::SENTIMENT_COUNTER_PENALTY * 0.5;
            }
        }

        return 0.0;
    }
}
