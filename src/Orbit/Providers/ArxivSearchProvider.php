<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * arXiv search adapter.
 *
 * T1.3 preprint server. Free, no auth.
 * arXiv only returns Atom XML — we parse with SimpleXML.
 *
 * Polite throttling: arXiv requests at most 1 query per 3 seconds. We won't
 * enforce sleep here (the orchestrator handles concurrency) but we use
 * conservative timeouts.
 *
 * Docs: https://info.arxiv.org/help/api/user-manual.html
 *
 * Options:
 *   - 'count'    (int):    max results (default 10, max 50)
 *   - 'sort'     (string): 'relevance' (default) | 'lastUpdatedDate' | 'submittedDate'
 */
final class ArxivSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT        = 'https://export.arxiv.org/api/query';
    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 50;
    private const TIMEOUT_SECONDS = 15;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (https://aivoedge.net; mailto:paul@aivoedge.net)';

    public function getName(): string
    {
        return 'arxiv';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count   = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $sortBy  = $this->validateSort($options['sort'] ?? 'relevance');

        $params = [
            'search_query' => 'all:' . $query,
            'start'        => 0,
            'max_results'  => $count,
            'sortBy'       => $sortBy,
            'sortOrder'    => 'descending',
        ];

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$body, $httpCode, $err] = $this->httpGet($url);
        if ($body === null) {
            throw new SearchProviderException("arXiv request failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("arXiv returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        // Parse Atom XML
        libxml_use_internal_errors(true);
        try {
            $xml = new SimpleXMLElement($body);
        } catch (Throwable $e) {
            throw new SearchProviderException("arXiv XML parse failed: {$e->getMessage()}");
        }

        $candidates = [];
        if (!isset($xml->entry)) {
            return $candidates;
        }

        foreach ($xml->entry as $entry) {
            $candidate = $this->mapEntry($entry);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function mapEntry(SimpleXMLElement $entry): ?CandidateResult
    {
        $url = '';
        if (isset($entry->id)) {
            $url = trim((string) $entry->id);
        }
        // Prefer the "alternate" link (HTML abstract page) over the id URI when available
        if (isset($entry->link)) {
            foreach ($entry->link as $link) {
                $attrs = $link->attributes();
                $rel  = isset($attrs['rel'])  ? (string) $attrs['rel']  : 'alternate';
                $type = isset($attrs['type']) ? (string) $attrs['type'] : '';
                $href = isset($attrs['href']) ? (string) $attrs['href'] : '';
                if ($rel === 'alternate' && $type === 'text/html' && $href !== '') {
                    $url = $href;
                    break;
                }
            }
        }
        if ($url === '') {
            return null;
        }

        $title = isset($entry->title) ? trim(preg_replace('/\s+/', ' ', (string) $entry->title)) : '';

        $summary = null;
        if (isset($entry->summary)) {
            $summary = trim(preg_replace('/\s+/', ' ', (string) $entry->summary));
            if ($summary !== '' && mb_strlen($summary) > 600) {
                $summary = mb_substr($summary, 0, 600) . '…';
            }
        }

        $authorNames = [];
        if (isset($entry->author)) {
            foreach ($entry->author as $author) {
                if (isset($author->name)) {
                    $name = trim((string) $author->name);
                    if ($name !== '') {
                        $authorNames[] = $name;
                    }
                }
            }
        }
        $authorString = null;
        if ($authorNames !== []) {
            $authorString = count($authorNames) > 3
                ? implode(', ', array_slice($authorNames, 0, 3)) . ' et al.'
                : implode(', ', $authorNames);
        }

        $publishedAt = null;
        $publishedRaw = isset($entry->published) ? (string) $entry->published : (
            isset($entry->updated) ? (string) $entry->updated : ''
        );
        if ($publishedRaw !== '') {
            try {
                $publishedAt = new DateTimeImmutable($publishedRaw);
            } catch (Throwable) {
                // ignore
            }
        }

        // Convert the entry to array for raw_response
        $rawArray = json_decode(json_encode($entry), true);
        if (!is_array($rawArray)) {
            $rawArray = [];
        }

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $summary,
            author:         $authorString,
            sourcePlatform: 'arXiv',
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $rawArray,
        );
    }

    private function validateSort(string $sort): string
    {
        $map = [
            'relevance'       => 'relevance',
            'lastUpdatedDate' => 'lastUpdatedDate',
            'submittedDate'   => 'submittedDate',
            'date'            => 'submittedDate',
            'recent'          => 'lastUpdatedDate',
        ];
        return $map[$sort] ?? 'relevance';
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
                'Accept: application/atom+xml',
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
