<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * GitHub Search API adapter — searches repositories by default.
 *
 * Authenticated rate limit: 5,000 req/hr (uses GITHUB_TOKEN from Railway).
 * Unauthenticated: 60 req/hr (only used as fallback if no token).
 *
 * Docs: https://docs.github.com/en/rest/search/search
 *
 * Options:
 *   - 'count' (int):    max results (default 10, max 30)
 *   - 'sort'  (string): 'best-match' (default) | 'stars' | 'forks' | 'updated'
 *   - 'scope' (string): 'repositories' (default) | 'code'
 *                       'code' searches inside files but is more expensive and
 *                       restricted to authenticated requests on public code.
 */
final class GitHubSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT_REPOS  = 'https://api.github.com/search/repositories';
    private const ENDPOINT_CODE   = 'https://api.github.com/search/code';
    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 30;
    private const TIMEOUT_SECONDS = 10;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0';

    public function __construct(
        private readonly ?string $token = null
    ) {
    }

    public function getName(): string
    {
        return 'github';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $scope = ($options['scope'] ?? 'repositories') === 'code' ? 'code' : 'repositories';

        if ($scope === 'code' && ($this->token === null || trim($this->token) === '')) {
            throw new SearchProviderException(
                'GitHub code search requires authentication. Set GITHUB_TOKEN in Railway.'
            );
        }

        $sort = $this->validateSort($options['sort'] ?? null, $scope);

        $params = [
            'q'        => $query,
            'per_page' => $count,
        ];
        if ($sort !== null) {
            $params['sort']  = $sort;
            $params['order'] = 'desc';
        }

        $endpoint = $scope === 'code' ? self::ENDPOINT_CODE : self::ENDPOINT_REPOS;
        $url      = $endpoint . '?' . http_build_query($params);

        [$body, $httpCode, $err] = $this->httpGet($url);
        if ($body === null) {
            throw new SearchProviderException("GitHub search request failed: {$err}");
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new SearchProviderException(
                "GitHub auth/rate-limit error (HTTP {$httpCode}). Check GITHUB_TOKEN in Railway."
            );
        }
        if ($httpCode === 422) {
            // Common: malformed or unsupported query
            throw new SearchProviderException(
                'GitHub rejected the search query (HTTP 422). Often means an unsupported query syntax.'
            );
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("GitHub returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data  = json_decode($body, true);
        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $candidates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = $scope === 'code' ? $this->mapCodeResult($item) : $this->mapRepoResult($item);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function mapRepoResult(array $item): ?CandidateResult
    {
        $url = (string) ($item['html_url'] ?? '');
        if ($url === '') {
            return null;
        }

        $title  = (string) ($item['full_name'] ?? $item['name'] ?? '');
        $desc   = isset($item['description']) ? (string) $item['description'] : null;
        $stars  = isset($item['stargazers_count']) ? (int) $item['stargazers_count'] : null;
        $author = isset($item['owner']['login']) ? (string) $item['owner']['login'] : null;

        $snippetParts = [];
        if ($desc !== null && $desc !== '') {
            $snippetParts[] = $desc;
        }
        if ($stars !== null) {
            $snippetParts[] = number_format($stars) . ' stars';
        }
        if (!empty($item['language'])) {
            $snippetParts[] = (string) $item['language'];
        }
        $snippet = $snippetParts !== [] ? implode(' • ', $snippetParts) : null;

        $publishedAt = null;
        if (!empty($item['updated_at']) && is_string($item['updated_at'])) {
            try {
                $publishedAt = new DateTimeImmutable($item['updated_at']);
            } catch (Throwable) {
                // ignore
            }
        }

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $snippet,
            author:         $author,
            sourcePlatform: 'GitHub',
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $item,
        );
    }

    private function mapCodeResult(array $item): ?CandidateResult
    {
        $url = (string) ($item['html_url'] ?? '');
        if ($url === '') {
            return null;
        }

        $repoName = isset($item['repository']['full_name']) ? (string) $item['repository']['full_name'] : '';
        $path     = isset($item['path']) ? (string) $item['path'] : '';
        $title    = $repoName !== '' && $path !== '' ? "{$repoName}/{$path}" : ($path !== '' ? $path : $repoName);

        $author = isset($item['repository']['owner']['login'])
            ? (string) $item['repository']['owner']['login']
            : null;

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        null, // GitHub code search doesn't return matched lines without an extra fetch
            author:         $author,
            sourcePlatform: 'GitHub',
            publishedAt:    null,
            sentimentHint:  null,
            rawResponse:    $item,
        );
    }

    private function validateSort(?string $sort, string $scope): ?string
    {
        if ($sort === null || $sort === '' || $sort === 'best-match') {
            return null; // GitHub's default scoring
        }
        $valid = $scope === 'code'
            ? ['indexed']
            : ['stars', 'forks', 'updated', 'help-wanted-issues'];
        return in_array($sort, $valid, true) ? $sort : null;
    }

    /**
     * @return array{0: ?string, 1: int, 2: string}
     */
    private function httpGet(string $url): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'Accept-Encoding: gzip',
        ];
        if ($this->token !== null && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
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
