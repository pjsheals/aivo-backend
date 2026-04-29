<?php

declare(strict_types=1);

namespace Aivo\Orbit\Controllers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\Exceptions\SearchProviderException;
use Aivo\Orbit\Providers\BraveSearchProvider;
use Aivo\Orbit\Providers\GitHubSearchProvider;
use Aivo\Orbit\Providers\WikidataSearchProvider;
use Aivo\Orbit\Providers\WikipediaSearchProvider;
use InvalidArgumentException;
use Throwable;

/**
 * Admin/test endpoints for ORBIT, used during build stages to verify
 * each search provider before it gets wired into the orchestrator.
 *
 * Auth: X-Meridian-Secret header (matches existing super-admin pattern).
 *
 * Routes:
 *   POST /api/orbit/admin/test-brave   (kept for back-compat with Stage 3)
 *   POST /api/orbit/admin/test-search  (generic — accepts "provider" parameter)
 */
class OrbitTestController
{
    /**
     * POST /api/orbit/admin/test-brave
     *
     * Stage 3 endpoint — kept so existing test scripts keep working.
     * Internally just calls the generic dispatch with provider='brave'.
     */
    public function testBrave(): void
    {
        if (!$this->authorise()) {
            return;
        }
        $body = $this->readJsonBody();
        $body['provider'] = 'brave';
        $this->dispatch($body);
    }

    /**
     * POST /api/orbit/admin/test-search
     *
     * Headers:
     *   X-Meridian-Secret: <MERIDIAN_INTERNAL_SECRET>
     *   Content-Type:      application/json
     *
     * Body:
     * {
     *   "provider": "wikipedia" | "wikidata" | "github" | "brave",
     *   "query":    "Charlotte Tilbury Magic Cream review",
     *   "options":  { ... provider-specific ... }
     * }
     *
     * The "options" object is passed straight through to the provider's
     * search() method. See each provider's class docblock for valid keys.
     */
    public function testSearch(): void
    {
        if (!$this->authorise()) {
            return;
        }
        $body = $this->readJsonBody();
        $this->dispatch($body);
    }

    // -------------------------------------------------------------------------
    // Internal dispatch
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

        // Accept options either at the top level (legacy: site/count/freshness flat)
        // or nested under 'options'
        $options = is_array($body['options'] ?? null) ? $body['options'] : [];
        foreach (['site', 'count', 'freshness', 'country', 'language', 'type', 'sort', 'scope'] as $passthrough) {
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
            json_response([
                'error'   => 'Provider construction failed',
                'message' => $e->getMessage(),
            ]);
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
            json_response([
                'error'    => 'Search provider error',
                'provider' => $provider->getName(),
                'message'  => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response([
                'error'    => 'Internal error',
                'provider' => $provider->getName(),
                'message'  => $e->getMessage(),
            ]);
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

            default:
                throw new InvalidArgumentException(
                    "Unknown provider '{$name}'. Valid: brave, wikipedia, wikidata, github."
                );
        }
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
