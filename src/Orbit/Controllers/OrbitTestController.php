<?php

declare(strict_types=1);

namespace Aivo\Orbit\Controllers;

use Aivo\Orbit\Exceptions\SearchProviderException;
use Aivo\Orbit\Providers\BraveSearchProvider;
use Throwable;

/**
 * Admin/test endpoints for ORBIT, used during build stages to verify
 * each search provider before it gets wired into the orchestrator.
 *
 * Auth: X-Meridian-Secret header (matches existing super-admin pattern).
 *
 * Routes:
 *   POST /api/orbit/admin/test-brave
 */
class OrbitTestController
{
    /**
     * POST /api/orbit/admin/test-brave
     *
     * Headers:
     *   X-Meridian-Secret: aivo-admin-2026
     *   Content-Type:      application/json
     *
     * Body:
     * {
     *   "query":     "Charlotte Tilbury Magic Cream review",
     *   "site":      "reddit.com",   // optional — runs site:reddit.com {query}
     *   "count":     5,              // optional — 1..20, default 10
     *   "freshness": "pm"            // optional — pd|pw|pm|py
     * }
     */
    public function testBrave(): void
    {
        if (!$this->authorise()) {
            return;
        }

        $body = $this->readJsonBody();

        $query = trim((string) ($body['query'] ?? ''));
        if ($query === '') {
            http_response_code(400);
            json_response(['error' => 'Missing required parameter: query']);
            return;
        }

        $options = [];
        if (!empty($body['site'])) {
            $options['site'] = (string) $body['site'];
        }
        if (isset($body['count'])) {
            $options['count'] = (int) $body['count'];
        }
        if (!empty($body['freshness'])) {
            $options['freshness'] = (string) $body['freshness'];
        }
        if (!empty($body['country'])) {
            $options['country'] = (string) $body['country'];
        }

        $apiKey = (string) env('BRAVE_SEARCH_API_KEY', '');
        if ($apiKey === '') {
            http_response_code(500);
            json_response([
                'error'   => 'Configuration error',
                'message' => 'BRAVE_SEARCH_API_KEY is not set in Railway environment.',
            ]);
            return;
        }

        try {
            $provider = new BraveSearchProvider($apiKey);

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
                'provider' => 'brave',
                'message'  => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response([
                'error'   => 'Internal error',
                'message' => $e->getMessage(),
            ]);
        }
    }

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
