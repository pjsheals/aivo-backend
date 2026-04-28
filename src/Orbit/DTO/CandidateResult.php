<?php

declare(strict_types=1);

namespace Aivo\Orbit\DTO;

use DateTimeImmutable;

/**
 * Standardised candidate result returned by every ORBIT search adapter.
 *
 * Field set matches what orbit_search_results expects, minus the scoring
 * fields (which are computed by the orchestrator in Stage 5).
 */
final class CandidateResult
{
    /**
     * @param string                 $url            The candidate URL (required).
     * @param string|null            $title          Result title.
     * @param string|null            $snippet        Short description / excerpt.
     * @param string|null            $author         Author name when available (rare in web search).
     * @param string|null            $sourcePlatform Platform name (e.g. 'wikipedia.org', 'Crossref').
     *                                               Filled by adapter; orchestrator may overwrite
     *                                               using citation_platforms.platform_name.
     * @param DateTimeImmutable|null $publishedAt    Original publication date when available.
     * @param string|null            $sentimentHint  'positive'|'neutral'|'negative'|'counter'|null.
     *                                               Most adapters leave this null — sentiment is
     *                                               classified downstream.
     * @param array                  $rawResponse    Original API response payload for debugging
     *                                               and re-scoring. Stored in
     *                                               orbit_search_results.raw_response (jsonb).
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $snippet = null,
        public readonly ?string $author = null,
        public readonly ?string $sourcePlatform = null,
        public readonly ?DateTimeImmutable $publishedAt = null,
        public readonly ?string $sentimentHint = null,
        public readonly array $rawResponse = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'url'             => $this->url,
            'title'           => $this->title,
            'snippet'         => $this->snippet,
            'author'          => $this->author,
            'source_platform' => $this->sourcePlatform,
            'published_at'    => $this->publishedAt?->format(DATE_ATOM),
            'sentiment_hint'  => $this->sentimentHint,
            'raw_response'    => $this->rawResponse,
        ];
    }
}
