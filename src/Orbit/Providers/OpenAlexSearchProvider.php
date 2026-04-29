<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * OpenAlex search adapter — open, free scholarly graph (200M+ works).
 *
 * T1.2 peer-reviewed academic. Free, no auth required.
 * Polite pool: include mailto in User-Agent (faster, more reliable).
 *
 * Docs: https://docs.openalex.org/api-entities/works/search-works
 *
 * Options:
 *   - 'count' (int):    max results (default 10, max 50)
 *   - 'sort'  (string): 'relevance_score:desc' (default) | 'cited_by_count:desc' |
 *                       'publication_year:desc' | 'publication_date:desc'
 */
final class OpenAlexSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT        = 'https://api.openalex.org/works';
    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 50;
    private const TIMEOUT_SECONDS = 12;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (mailto:paul@aivoedge.net)';

    public function getName(): string
    {
        return 'openalex';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $sort  = $this->validateSort($options['sort'] ?? 'relevance_score:desc');

        $params = [
            'search'   => $query,
            'per-page' => $count,
            'sort'     => $sort,
            'mailto'   => 'paul@aivoedge.net',
        ];

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$body, $httpCode, $err] = $this->httpGet($url);
        if ($body === null) {
            throw new SearchProviderException("OpenAlex request failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("OpenAlex returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data    = json_decode($body, true);
        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            return [];
        }

        $candidates = [];
        foreach ($results as $work) {
            if (!is_array($work)) {
                continue;
            }
            $candidate = $this->mapWork($work);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function mapWork(array $work): ?CandidateResult
    {
        // Prefer DOI URL, fall back to OpenAlex ID URL
        $doi = isset($work['doi']) ? (string) $work['doi'] : '';
        $url = $doi !== '' ? $doi : (string) ($work['id'] ?? '');
        if ($url === '') {
            return null;
        }

        $title = isset($work['title']) ? (string) $work['title'] : (
            isset($work['display_name']) ? (string) $work['display_name'] : ''
        );

        // Author list
        $authorNames = [];
        if (isset($work['authorships']) && is_array($work['authorships'])) {
            foreach ($work['authorships'] as $auth) {
                if (!is_array($auth)) {
                    continue;
                }
                $name = $auth['author']['display_name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $authorNames[] = $name;
                }
            }
        }
        $authorString = null;
        if ($authorNames !== []) {
            $authorString = count($authorNames) > 3
                ? implode(', ', array_slice($authorNames, 0, 3)) . ' et al.'
                : implode(', ', $authorNames);
        }

        // OpenAlex returns abstracts as inverted index; reconstruct
        $snippet = null;
        if (isset($work['abstract_inverted_index']) && is_array($work['abstract_inverted_index'])) {
            $reconstructed = $this->reconstructAbstract($work['abstract_inverted_index']);
            if ($reconstructed !== '') {
                if (mb_strlen($reconstructed) > 600) {
                    $reconstructed = mb_substr($reconstructed, 0, 600) . '…';
                }
                $snippet = $reconstructed;
            }
        }

        // Publication venue + cited-by count for context
        $venue = $work['primary_location']['source']['display_name'] ?? null;
        $citedBy = isset($work['cited_by_count']) ? (int) $work['cited_by_count'] : null;
        $bits = [];
        if ($snippet !== null) {
            $bits[] = $snippet;
        }
        if (is_string($venue) && $venue !== '') {
            $bits[] = $venue;
        }
        if ($citedBy !== null) {
            $bits[] = "Cited by {$citedBy}";
        }
        $snippet = $bits !== [] ? implode(' • ', $bits) : null;

        $publishedAt = null;
        if (!empty($work['publication_date']) && is_string($work['publication_date'])) {
            try {
                $publishedAt = new DateTimeImmutable($work['publication_date']);
            } catch (Throwable) {
                // ignore
            }
        }

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $snippet,
            author:         $authorString,
            sourcePlatform: 'OpenAlex',
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $work,
        );
    }

    /**
     * OpenAlex stores abstracts as inverted indexes:
     *   {"word": [position1, position2], ...}
     * Reconstruct by placing each word at its lowest position.
     */
    private function reconstructAbstract(array $inverted): string
    {
        $positions = [];
        foreach ($inverted as $word => $posList) {
            if (!is_array($posList)) {
                continue;
            }
            foreach ($posList as $p) {
                if (is_int($p) || (is_numeric($p) && (int) $p == $p)) {
                    $positions[(int) $p] = (string) $word;
                }
            }
        }
        if ($positions === []) {
            return '';
        }
        ksort($positions);
        return implode(' ', $positions);
    }

    private function validateSort(string $sort): string
    {
        $valid = [
            'relevance_score:desc',
            'cited_by_count:desc',
            'publication_year:desc',
            'publication_date:desc',
            'updated_date:desc',
        ];
        return in_array($sort, $valid, true) ? $sort : 'relevance_score:desc';
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
