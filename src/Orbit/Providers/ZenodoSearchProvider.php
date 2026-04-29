<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * Zenodo search adapter — CERN-hosted DOI repository.
 *
 * T1.3 DOI repository. Free, no auth for read access.
 * Especially relevant for AIVO since AIVO Standard publishes its own
 * methodology and atoms here (e.g. brand.context DOI 10.5281/zenodo.19588523).
 *
 * Docs: https://developers.zenodo.org/#list36
 *
 * Options:
 *   - 'count' (int):    max results (default 10, max 100)
 *   - 'sort'  (string): 'bestmatch' (default) | 'mostrecent' | 'mostviewed'
 *   - 'type'  (string): optional resource type filter — 'publication' |
 *                       'dataset' | 'software' | 'video' etc.
 */
final class ZenodoSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT        = 'https://zenodo.org/api/records';
    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 100;
    private const TIMEOUT_SECONDS = 12;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (https://aivoedge.net; mailto:paul@aivoedge.net)';

    public function getName(): string
    {
        return 'zenodo';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $sort  = $this->validateSort($options['sort'] ?? 'bestmatch');

        $params = [
            'q'    => $query,
            'size' => $count,
            'sort' => $sort,
        ];
        if (!empty($options['type'])) {
            $params['type'] = (string) $options['type'];
        }

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$body, $httpCode, $err] = $this->httpGet($url);
        if ($body === null) {
            throw new SearchProviderException("Zenodo request failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("Zenodo returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        $hits = $data['hits']['hits'] ?? null;
        if (!is_array($hits)) {
            return [];
        }

        $candidates = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $candidate = $this->mapHit($hit);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function mapHit(array $hit): ?CandidateResult
    {
        // Prefer DOI URL when available, fall back to the record's HTML page
        $doi = isset($hit['doi']) ? (string) $hit['doi'] : '';
        $url = '';
        if ($doi !== '') {
            $url = "https://doi.org/{$doi}";
        } elseif (!empty($hit['links']['self_html']) && is_string($hit['links']['self_html'])) {
            $url = $hit['links']['self_html'];
        } elseif (!empty($hit['links']['html']) && is_string($hit['links']['html'])) {
            $url = $hit['links']['html'];
        }
        if ($url === '') {
            return null;
        }

        $meta = isset($hit['metadata']) && is_array($hit['metadata']) ? $hit['metadata'] : [];

        $title = isset($meta['title']) ? trim((string) $meta['title']) : '';

        // Authors live under metadata.creators[].name
        $authorNames = [];
        if (isset($meta['creators']) && is_array($meta['creators'])) {
            foreach ($meta['creators'] as $creator) {
                if (is_array($creator) && isset($creator['name']) && is_string($creator['name'])) {
                    $authorNames[] = trim($creator['name']);
                }
            }
        }
        $authorString = null;
        if ($authorNames !== []) {
            $authorString = count($authorNames) > 3
                ? implode(', ', array_slice($authorNames, 0, 3)) . ' et al.'
                : implode(', ', $authorNames);
        }

        // Description is HTML — strip tags for snippet
        $snippet = null;
        if (!empty($meta['description']) && is_string($meta['description'])) {
            $desc = trim(strip_tags($meta['description']));
            if ($desc !== '') {
                if (mb_strlen($desc) > 600) {
                    $desc = mb_substr($desc, 0, 600) . '…';
                }
                $snippet = $desc;
            }
        }

        // Resource type and DOI add useful context
        $bits = [];
        if ($snippet !== null) {
            $bits[] = $snippet;
        }
        if (!empty($meta['resource_type']['title']) && is_string($meta['resource_type']['title'])) {
            $bits[] = (string) $meta['resource_type']['title'];
        }
        if ($doi !== '') {
            $bits[] = "DOI: {$doi}";
        }
        $snippet = $bits !== [] ? implode(' • ', $bits) : null;

        $publishedAt = null;
        $dateRaw = $meta['publication_date'] ?? $hit['created'] ?? null;
        if (is_string($dateRaw) && $dateRaw !== '') {
            try {
                $publishedAt = new DateTimeImmutable($dateRaw);
            } catch (Throwable) {
                // ignore
            }
        }

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $snippet,
            author:         $authorString,
            sourcePlatform: 'Zenodo',
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $hit,
        );
    }

    private function validateSort(string $sort): string
    {
        $valid = ['bestmatch', 'mostrecent', 'mostviewed'];
        return in_array($sort, $valid, true) ? $sort : 'bestmatch';
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
