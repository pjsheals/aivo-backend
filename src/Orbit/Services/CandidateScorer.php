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
 *                   + sector_match_bonus_points
 *                   - sentiment_penalty
 *
 * All component values are exposed via score(), so the orchestrator can
 * persist them denormalised in orbit_search_results. Historical scores
 * stay reproducible even if citation_platforms.score_base is later edited.
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
     * Score a candidate against a claim's embedding and the platform metadata.
     *
     * @param CandidateResult     $candidate
     * @param array               $platform           Row from citation_platforms (or sensible defaults).
     *                                                Keys used: score_base, sector (TEXT[]), tags (TEXT[]).
     * @param float[]             $claimEmbedding     Pre-computed claim embedding.
     * @param float[]             $candidateEmbedding Pre-computed candidate embedding.
     * @param string[]            $brandSectors       e.g. ['beauty','cpg'] from brand row.
     * @param string              $requestedSentiment 'positive'|'neutral'|'any'.
     *
     * @return array{
     *   base_tier_score: float,
     *   recency_multiplier: float,
     *   relevance_multiplier: float,
     *   sector_match_bonus: float,
     *   sentiment_penalty: float,
     *   candidate_score: float,
     *   relevance_cosine: float
     * }
     */
    public function score(
        CandidateResult $candidate,
        array $platform,
        array $claimEmbedding,
        array $candidateEmbedding,
        array $brandSectors,
        string $requestedSentiment = 'positive'
    ): array {
        // 1. base_tier_score — from citation_platforms row
        $baseTierScore = isset($platform['score_base'])
            ? (float) $platform['score_base']
            : 15.0; // T3.9 fallback if no platform classified

        // 2. recency_multiplier
        $recencyMultiplier = $this->recencyMultiplier($candidate->publishedAt);

        // 3. relevance_multiplier — cosine similarity scaled
        $cosine = EmbeddingService::cosineSimilarity($claimEmbedding, $candidateEmbedding);
        // Clamp negatives to 0 (rare with OpenAI embeddings but possible for unrelated content)
        $cosine = max(0.0, min(1.0, $cosine));
        // Map [0..1] → [0..1.5] so a perfect semantic match amplifies, a poor one shrinks
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
        // proportional to the candidate's strength — a sector match on a weak
        // candidate doesn't artificially elevate it.
        $score += ($sectorMatchBonus * $baseTierScore);
        $score -= $sentimentPenalty;

        // Floor at zero — negative scores are noise
        $score = max(0.0, $score);

        return [
            'base_tier_score'      => round($baseTierScore, 2),
            'recency_multiplier'   => round($recencyMultiplier, 3),
            'relevance_multiplier' => round($relevanceMultiplier, 3),
            'sector_match_bonus'   => round($sectorMatchBonus, 3),
            'sentiment_penalty'    => round($sentimentPenalty, 2),
            'candidate_score'      => round($score, 2),
            'relevance_cosine'     => round($cosine, 4),
        ];
    }

    /**
     * Linear decay from 1.0 at <30d old, down to 0.5 at 5 years, floor at 0.5.
     */
    private function recencyMultiplier(?DateTimeImmutable $publishedAt): float
    {
        if ($publishedAt === null) {
            // Unknown date → assume middle of the road, neither penalised nor rewarded
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

        // Linear decay from 1.0 to 0.5 between fresh_days and decay_days
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
        // Empty platform sector list = applies to all sectors
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
                return self::SENTIMENT_COUNTER_PENALTY * 0.5; // softer when hint is from the candidate not the platform
            }
        }

        return 0.0;
    }
}
