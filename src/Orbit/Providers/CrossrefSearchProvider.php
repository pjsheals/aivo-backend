<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * Crossref search adapter — DOI-indexed academic literature.
 *
 * T1.2 peer-reviewed academic. Free, no auth required.
 * Crossref expects a "polite pool" mailto in the User-Agent for better rate
 * limiting; we include paul@aivoedge.net as the contact address.
 *
 * Docs: https://api.crossref.org/swagger-ui/index.html
 *
 * Options:
 *   - 'count'    (int):    max results (default 10, max 50)
 *   - 'sort'     (string): 'relevance' (default) | 'published' | 'is-referenced-by-count'
 */
final class CrossrefSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT        = 'https://api.crossref.org/works';
    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 50;
    private const TIMEOUT_SECONDS = 12;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (https://aivoedge.net; mailto:paul@aivoedge.net)';

    public function getName(): string
    {
        return 'crossref';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $sort  = $this->validateSort($options['sort'] ?? 'relevance');

        $params = [
            'query' => $query,
            'rows'  => $count,
            'sort'  => $sort,
            'order' => $sort === 'relevance' ? 'desc' : 'desc',
            'select' => 'DOI,title,author,abstract,published-print,published-online,created,container-title,publisher,type,URL,is-referenced-by-count',
        ];

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$body, $httpCode, $err] = $this->httpGet($url);
        if ($body === null) {
            throw new SearchProviderException("Crossref request failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("Crossref returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data  = json_decode($body, true);
        $items = $data['message']['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $candidates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = $this->mapItem($item);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function mapItem(array $item): ?CandidateResult
    {
        $doi = (string) ($item['DOI'] ?? '');
        if ($doi === '') {
            return null;
        }

        $url = (string) ($item['URL'] ?? "https://doi.org/{$doi}");

        $title = '';
        if (isset($item['title']) && is_array($item['title']) && $item['title'] !== []) {
            $title = (string) $item['title'][0];
        }

        // Author list — first 3 names, "et al." if more
        $authorNames = [];
        if (isset($item['author']) && is_array($item['author'])) {
            foreach ($item['author'] as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $given  = isset($a['given'])  ? (string) $a['given']  : '';
                $family = isset($a['family']) ? (string) $a['family'] : '';
                $name   = trim($given . ' ' . $family);
                if ($name !== '') {
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

        // Abstract — Crossref returns it as JATS XML or plain text; strip tags
        $snippet = null;
        if (!empty($item['abstract']) && is_string($item['abstract'])) {
            $abs = trim(strip_tags($item['abstract']));
            if ($abs !== '') {
                if (mb_strlen($abs) > 600) {
                    $abs = mb_substr($abs, 0, 600) . '…';
                }
                $snippet = $abs;
            }
        }
        // If no abstract, build a snippet from container/publisher
        if ($snippet === null) {
            $containerTitle = isset($item['container-title']) && is_array($item['container-title']) && $item['container-title'] !== []
                ? (string) $item['container-title'][0]
                : null;
            $publisher = isset($item['publisher']) ? (string) $item['publisher'] : null;
            $bits = array_filter([$containerTitle, $publisher]);
            if ($bits !== []) {
                $snippet = implode(' • ', $bits);
            }
        }

        // Citation count is a strong relevance signal
        if (isset($item['is-referenced-by-count'])) {
            $cited = (int) $item['is-referenced-by-count'];
            $snippet = ($snippet !== null ? $snippet . ' • ' : '') . "Cited by {$cited}";
        }

        $publishedAt = $this->parsePublishedDate($item);

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $snippet,
            author:         $authorString,
            sourcePlatform: 'Crossref',
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $item,
        );
    }

    private function parsePublishedDate(array $item): ?DateTimeImmutable
    {
        // Try published-print, then published-online, then created
        foreach (['published-print', 'published-online', 'created'] as $key) {
            $parts = $item[$key]['date-parts'][0] ?? null;
            if (is_array($parts) && isset($parts[0])) {
                $year  = (int) $parts[0];
                $month = isset($parts[1]) ? (int) $parts[1] : 1;
                $day   = isset($parts[2]) ? (int) $parts[2] : 1;
                if ($year >= 1900 && $year <= 2100) {
                    try {
                        return new DateTimeImmutable(sprintf('%04d-%02d-%02dT00:00:00Z', $year, max(1,$month), max(1,$day)));
                    } catch (Throwable) {
                        // fall through
                    }
                }
            }
        }
        return null;
    }

    private function validateSort(string $sort): string
    {
        $valid = ['relevance', 'published', 'is-referenced-by-count', 'updated', 'created'];
        return in_array($sort, $valid, true) ? $sort : 'relevance';
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
