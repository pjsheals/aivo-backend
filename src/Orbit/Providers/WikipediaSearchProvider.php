<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * Wikipedia (MediaWiki) Search API adapter.
 *
 * Two-step approach:
 *   1. action=query&list=search   → find matching articles by relevance
 *   2. action=query&prop=extracts → fetch a clean text excerpt for each hit
 *
 * Free, no auth, polite rate limit (300 req/min sustained).
 * Docs: https://www.mediawiki.org/wiki/API:Search
 *
 * Options:
 *   - 'count'    (int):    max results (default 10, max 50)
 *   - 'language' (string): wiki language code, default 'en'
 */
final class WikipediaSearchProvider implements SearchProviderInterface
{
    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 50;
    private const TIMEOUT_SECONDS = 10;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (https://aivoedge.net; paul@aivoedge.net)';

    public function getName(): string
    {
        return 'wikipedia';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count    = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $language = preg_replace('/[^a-z]/', '', strtolower((string) ($options['language'] ?? 'en'))) ?: 'en';
        $endpoint = "https://{$language}.wikipedia.org/w/api.php";

        // Step 1 — find article titles
        $searchParams = [
            'action'   => 'query',
            'list'     => 'search',
            'srsearch' => $query,
            'srlimit'  => $count,
            'srprop'   => 'snippet|timestamp',
            'format'   => 'json',
            'origin'   => '*',
        ];

        [$body, $httpCode, $err] = $this->httpGet($endpoint . '?' . http_build_query($searchParams));
        if ($body === null) {
            throw new SearchProviderException("Wikipedia search request failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("Wikipedia returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['query']['search']) || !is_array($data['query']['search'])) {
            return [];
        }

        $hits = $data['query']['search'];
        if ($hits === []) {
            return [];
        }

        // Step 2 — fetch plain-text extracts for the matched titles in one call
        $titles = array_map(static fn ($h) => (string) ($h['title'] ?? ''), $hits);
        $titles = array_values(array_filter($titles, static fn ($t) => $t !== ''));
        $extracts = $this->fetchExtracts($endpoint, $titles);

        $candidates = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $title = (string) ($hit['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $url = sprintf(
                'https://%s.wikipedia.org/wiki/%s',
                $language,
                str_replace(' ', '_', rawurlencode($title))
            );

            $publishedAt = null;
            if (!empty($hit['timestamp']) && is_string($hit['timestamp'])) {
                try {
                    $publishedAt = new DateTimeImmutable($hit['timestamp']);
                } catch (Throwable) {
                    // ignore unparseable timestamps
                }
            }

            $snippet = $extracts[$title]
                ?? (isset($hit['snippet']) ? strip_tags((string) $hit['snippet']) : null);

            $candidates[] = new CandidateResult(
                url:            $url,
                title:          $title,
                snippet:        $snippet !== null && $snippet !== '' ? $snippet : null,
                author:         null,
                sourcePlatform: 'Wikipedia',
                publishedAt:    $publishedAt,
                sentimentHint:  null,
                rawResponse:    $hit,
            );
        }

        return $candidates;
    }

    /**
     * @param string[] $titles
     * @return array<string,string> title => extract text
     */
    private function fetchExtracts(string $endpoint, array $titles): array
    {
        if ($titles === []) {
            return [];
        }

        $params = [
            'action'      => 'query',
            'prop'        => 'extracts',
            'exintro'     => 1,
            'explaintext' => 1,
            'exlimit'     => 'max',
            'titles'      => implode('|', array_slice($titles, 0, 50)),
            'format'      => 'json',
            'origin'      => '*',
        ];

        [$body, $httpCode, $err] = $this->httpGet($endpoint . '?' . http_build_query($params));
        if ($body === null || $httpCode < 200 || $httpCode >= 300) {
            // Soft fail — fall back to snippets in main response.
            return [];
        }

        $data = json_decode($body, true);
        $pages = $data['query']['pages'] ?? null;
        if (!is_array($pages)) {
            return [];
        }

        $out = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $t = isset($page['title']) ? (string) $page['title'] : null;
            $e = isset($page['extract']) ? trim((string) $page['extract']) : null;
            if ($t !== null && $e !== null && $e !== '') {
                // Truncate aggressively — orchestrator only needs a few sentences.
                if (mb_strlen($e) > 600) {
                    $e = mb_substr($e, 0, 600) . '…';
                }
                $out[$t] = $e;
            }
        }

        return $out;
    }

    /**
     * @return array{0: ?string, 1: int, 2: string}
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Encoding: gzip',
            ],
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $resp  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        if ($resp === false || $resp === null) {
            return [null, $code, $error !== '' ? $error : 'cURL error'];
        }
        return [(string) $resp, $code, ''];
    }
}
