<?php

declare(strict_types=1);

namespace Aivo\Orbit\Controllers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
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
use Aivo\Orbit\Services\CandidateScorer;
use Aivo\Orbit\Services\CitationPlatformResolver;
use Aivo\Orbit\Services\EmbeddingService;
use Aivo\Orbit\Services\OrbitSearchOrchestrator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Admin/test endpoints for ORBIT.
 *
 * Auth: X-Meridian-Secret header.
 *
 * Routes:
 *   POST /api/orbit/admin/test-brave   — Stage 3 single-provider test
 *   POST /api/orbit/admin/test-search  — Stage 4 generic single-provider test
 *   POST /api/orbit/admin/run-search   — Stage 5 full orchestrator pipeline
 */
class OrbitTestController
{
    public function testBrave(): void
    {
        if (!$this->authorise()) return;
        $body = $this->readJsonBody();
        $body['provider'] = 'brave';
        $this->dispatch($body);
    }

    public function testSearch(): void
    {
        if (!$this->authorise()) return;
        $this->dispatch($this->readJsonBody());
    }

    /**
     * POST /api/orbit/admin/run-search
     *
     * Body:
     * {
     *   "gap_id":              123,
     *   "requested_tiers":     ["T1.*","T2.*"],   // optional, defaults to all
     *   "requested_sentiment": "positive",         // optional
     *   "per_provider_cap":    5                   // optional, 1-10
     * }
     *
     * Returns the run summary with persisted run_id + ranked results.
     */
    public function runSearch(): void
    {
        if (!$this->authorise()) return;

        $body = $this->readJsonBody();
        $gapId = (int) ($body['gap_id'] ?? 0);
        if ($gapId <= 0) {
            http_response_code(400);
            json_response(['error' => 'Missing or invalid gap_id (must be positive integer).']);
            return;
        }

        $requestedTiers = [];
        if (!empty($body['requested_tiers']) && is_array($body['requested_tiers'])) {
            $requestedTiers = array_values(array_filter(
                array_map(static fn ($t) => trim((string) $t), $body['requested_tiers']),
                static fn ($t) => $t !== ''
            ));
        }

        $sentiment = (string) ($body['requested_sentiment'] ?? 'positive');
        if (!in_array($sentiment, ['positive', 'neutral', 'any'], true)) {
            http_response_code(400);
            json_response(['error' => "Invalid requested_sentiment. Use 'positive'|'neutral'|'any'."]);
            return;
        }

        $perCap = isset($body['per_provider_cap'])
            ? max(1, min(10, (int) $body['per_provider_cap']))
            : null;

        try {
            $orchestrator = $this->buildOrchestrator();
        } catch (InvalidArgumentException $e) {
            http_response_code(500);
            json_response(['error' => $e->getMessage()]);
            return;
        }

        try {
            $result = $orchestrator->run($gapId, $requestedTiers, $sentiment, $perCap);
            json_response(['success' => true] + $result);
        } catch (RuntimeException $e) {
            http_response_code(404);
            json_response(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response([
                'error'   => 'Orchestrator failure',
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Stage 4 generic single-provider dispatch
    // -------------------------------------------------------------------------

    private function dispatch(array $body): void
    {
        $providerName = strtolower(trim((string) ($body['provider'] ?? '')));
        if ($providerName === '') {
            http_response_code(400);
            json_response(['error' => 'Missing required parameter: provider']);
            return;
        }

        $query = trim((string) ($body['query'] ?? ''));
        if ($query === '') {
            http_response_code(400);
            json_response(['error' => 'Missing required parameter: query']);
            return;
        }

        $options = is_array($body['options'] ?? null) ? $body['options'] : [];
        foreach (['site','count','freshness','country','language','type','sort','scope'] as $passthrough) {
            if (isset($body[$passthrough]) && !isset($options[$passthrough])) {
                $options[$passthrough] = $body[$passthrough];
            }
        }

        try {
            $provider = $this->buildProvider($providerName);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            json_response(['error' => $e->getMessage()]);
            return;
        } catch (Throwable $e) {
            http_response_code(500);
            json_response(['error' => 'Provider construction failed', 'message' => $e->getMessage()]);
            return;
        }

        try {
            $startedAt = microtime(true);
            $results   = $provider->search($query, $options);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            json_response([
                'success'    => true,
                'provider'   => $provider->getName(),
                'query'      => $query,
                'options'    => $options,
                'count'      => count($results),
                'latency_ms' => $latencyMs,
                'results'    => array_map(static fn ($c) => $c->toArray(), $results),
            ]);
        } catch (SearchProviderException $e) {
            http_response_code(502);
            json_response(['error' => 'Search provider error', 'provider' => $provider->getName(), 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response(['error' => 'Internal error', 'provider' => $provider->getName(), 'message' => $e->getMessage()]);
        }
    }

    private function buildProvider(string $name): SearchProviderInterface
    {
        switch ($name) {
            case 'brave':
                $key = (string) env('BRAVE_SEARCH_API_KEY', '');
                if ($key === '') {
                    throw new InvalidArgumentException('BRAVE_SEARCH_API_KEY is not set in Railway.');
                }
                return new BraveSearchProvider($key);
            case 'wikipedia':
                return new WikipediaSearchProvider();
            case 'wikidata':
                return new WikidataSearchProvider();
            case 'github':
                $token = (string) env('GITHUB_TOKEN', '');
                return new GitHubSearchProvider($token !== '' ? $token : null);
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
                throw new InvalidArgumentException(
                    "Unknown provider '{$name}'. Valid: brave, wikipedia, wikidata, github, crossref, openalex, pubmed, arxiv, zenodo."
                );
        }
    }

    // -------------------------------------------------------------------------
    // Stage 5 orchestrator construction
    // -------------------------------------------------------------------------

    private function buildOrchestrator(): OrbitSearchOrchestrator
    {
        $openaiKey = (string) env('OPENAI_API_KEY', '');
        if ($openaiKey === '') {
            throw new InvalidArgumentException('OPENAI_API_KEY is not set in Railway.');
        }
        $braveKey = (string) env('BRAVE_SEARCH_API_KEY', '');
        if ($braveKey === '') {
            throw new InvalidArgumentException('BRAVE_SEARCH_API_KEY is not set in Railway.');
        }
        $githubToken = (string) env('GITHUB_TOKEN', '');

        return new OrbitSearchOrchestrator(
            embedder:    new EmbeddingService($openaiKey),
            resolver:    new CitationPlatformResolver(),
            scorer:      new CandidateScorer(),
            braveApiKey: $braveKey,
            githubToken: $githubToken !== '' ? $githubToken : null
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function authorise(): bool
    {
        $secret   = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
        $expected = (string) env('MERIDIAN_INTERNAL_SECRET', 'aivo-admin-2026');

        if (!is_string($secret) || !hash_equals($expected, $secret)) {
            http_response_code(403);
            json_response(['error' => 'Forbidden.']);
            return false;
        }
        return true;
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
