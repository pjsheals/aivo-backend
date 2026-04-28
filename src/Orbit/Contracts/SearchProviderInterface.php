<?php

declare(strict_types=1);

namespace Aivo\Orbit\Contracts;

use Aivo\Orbit\DTO\CandidateResult;

/**
 * Contract every ORBIT search adapter must implement.
 *
 * Stage 3: BraveSearchProvider
 * Stage 4: WikipediaSearchProvider, CrossrefSearchProvider, OpenAlexSearchProvider,
 *          PubMedSearchProvider, ArxivSearchProvider, ZenodoSearchProvider,
 *          GitHubSearchProvider, WikidataSearchProvider
 */
interface SearchProviderInterface
{
    /**
     * Unique short identifier (e.g. 'brave', 'wikipedia', 'crossref').
     * Used in logs, in orbit_search_runs.platforms_searched, and to route
     * search-routing decisions in the orchestrator.
     */
    public function getName(): string;

    /**
     * Execute a search.
     *
     * @param string $query   The search query string.
     * @param array  $options Provider-specific options. Common keys (Brave):
     *                          - 'site'       (string): restrict to domain (e.g. 'reddit.com')
     *                          - 'count'      (int):    max results (default 10, max 20)
     *                          - 'freshness'  (string): 'pd'|'pw'|'pm'|'py' (past day/week/month/year)
     *                          - 'country'    (string): 2-letter ISO country code
     *
     * @return CandidateResult[] Standardised candidate result objects.
     *
     * @throws \Aivo\Orbit\Exceptions\SearchProviderException
     *         When the provider rejects the request or returns an unparseable response.
     */
    public function search(string $query, array $options = []): array;
}
