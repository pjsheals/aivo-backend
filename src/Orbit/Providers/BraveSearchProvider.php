<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * Brave Search API adapter.
 *
 * Two roles in ORBIT:
 *   1. T3 fallback — surface candidates from the open web when no direct API
 *      adapter covers the platform we want to search (Reddit, Quora, Trustpilot, etc.)
 *   2. site_search — for citation_platforms with search_method='site_search'
 *      (e.g. GOV.UK), pass options['site'] = domain and Brave does
 *      `site:domain.com {query}` for us.
 *
 * Free tier: 1 query/sec, 2,000/month.
 * Docs: https://api-dashboard.search.brave.com/app/documentation
 */
final class BraveSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
    private const DEFAULT_COUNT = 10;
    private const MAX_COUNT = 20;
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly string $apiKey
    ) {
        if (trim($this->apiKey) === '') {
            throw new \InvalidArgumentException(
                'BraveSearchProvider requires a non-empty BRAVE_SEARCH_API_KEY.'
            );
        }
    }

    public function getName(): string
    {
        return 'brave';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // site: restriction — used for citation_platforms.search_method='site_search'
        if (!empty($options['site'])) {
            $query = 'site:' . trim((string) $options['site']) . ' ' . $query;
        }

        $count = (int) ($options['count'] ?? self::DEFAULT_COUNT);
        $count = max(1, min(self::MAX_COUNT, $count));

        $params = [
            'q'          => $query,
            'count'      => $count,
            'safesearch' => 'moderate',
        ];

        if (!empty($options['freshness']) && in_array($options['freshness'], ['pd', 'pw', 'pm', 'py'], true)) {
            $params['freshness'] = $options['freshness'];
        }

        if (!empty($options['country'])) {
            $params['country'] = strtoupper(substr((string) $options['country'], 0, 2));
        }

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$body, $httpCode, $curlError] = $this->httpGet($url);

        if ($body === null) {
            throw new SearchProviderException(
                "Brave Search request failed: {$curlError}"
            );
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new SearchProviderException(
                "Brave Search auth failed (HTTP {$httpCode}). Check BRAVE_SEARCH_API_KEY in Railway."
            );
        }

        if ($httpCode === 429) {
            throw new SearchProviderException(
                'Brave Search rate limit hit (HTTP 429). Free tier is 1 query/sec.'
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException(
                "Brave Search returned HTTP {$httpCode}: " . substr($body, 0, 500)
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new SearchProviderException('Brave Search returned invalid JSON.');
        }

        $webResults = $data['web']['results'] ?? [];
        if (!is_array($webResults)) {
            return [];
        }

        $candidates = [];
        foreach ($webResults as $result) {
            if (!is_array($result)) {
                continue;
            }
            $candidate = $this->mapResult($result);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @return array{0: ?string, 1: int, 2: string} [body|null, httpCode, curlError]
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
                'X-Subscription-Token: ' . $this->apiKey,
            ],
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_USERAGENT      => 'AIVO-ORBIT/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === null) {
            return [null, $httpCode, $curlError !== '' ? $curlError : 'Unknown cURL error'];
        }

        return [(string) $response, $httpCode, ''];
    }

    private function mapResult(array $result): ?CandidateResult
    {
        $url = $result['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return null;
        }

        // Try a few date fields Brave sometimes returns
        $publishedAt = null;
        foreach (['page_age', 'age'] as $field) {
            if (!empty($result[$field]) && is_string($result[$field])) {
                try {
                    $publishedAt = new DateTimeImmutable($result[$field]);
                    break;
                } catch (Throwable) {
                    // try next field
                }
            }
        }

        // Source platform — prefer the profile, fall back to hostname
        $sourcePlatform = null;
        if (!empty($result['profile']['name']) && is_string($result['profile']['name'])) {
            $sourcePlatform = $result['profile']['name'];
        } elseif (!empty($result['meta_url']['hostname']) && is_string($result['meta_url']['hostname'])) {
            $sourcePlatform = $result['meta_url']['hostname'];
        }

        $title   = isset($result['title']) && is_string($result['title']) ? strip_tags($result['title']) : null;
        $snippet = isset($result['description']) && is_string($result['description']) ? strip_tags($result['description']) : null;

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $snippet !== '' ? $snippet : null,
            author:         null,
            sourcePlatform: $sourcePlatform,
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $result,
        );
    }
}
