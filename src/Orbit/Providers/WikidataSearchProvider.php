<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;

/**
 * Wikidata Search API adapter.
 *
 * Useful for entity verification (does this brand/person/product/concept
 * have a Wikidata entry? what is its Q-ID?). Returns structured matches
 * with descriptions.
 *
 * Free, no auth.
 * Docs: https://www.wikidata.org/w/api.php?action=help&modules=wbsearchentities
 *
 * Options:
 *   - 'count'    (int):    max results (default 7, max 50)
 *   - 'language' (string): result language code, default 'en'
 *   - 'type'     (string): 'item' (default) | 'property'
 */
final class WikidataSearchProvider implements SearchProviderInterface
{
    private const ENDPOINT        = 'https://www.wikidata.org/w/api.php';
    private const DEFAULT_COUNT   = 7;
    private const MAX_COUNT       = 50;
    private const TIMEOUT_SECONDS = 10;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (https://aivoedge.net; paul@aivoedge.net)';

    public function getName(): string
    {
        return 'wikidata';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count    = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $language = preg_replace('/[^a-z]/', '', strtolower((string) ($options['language'] ?? 'en'))) ?: 'en';
        $type     = ($options['type'] ?? 'item') === 'property' ? 'property' : 'item';

        $params = [
            'action'   => 'wbsearchentities',
            'search'   => $query,
            'language' => $language,
            'uselang'  => $language,
            'type'     => $type,
            'limit'    => $count,
            'format'   => 'json',
            'origin'   => '*',
        ];

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$body, $httpCode, $err] = $this->httpGet($url);
        if ($body === null) {
            throw new SearchProviderException("Wikidata search request failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("Wikidata returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        $hits = $data['search'] ?? null;
        if (!is_array($hits)) {
            return [];
        }

        $candidates = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $qid = (string) ($hit['id'] ?? '');
            if ($qid === '') {
                continue;
            }

            $url = (string) ($hit['concepturi'] ?? "https://www.wikidata.org/wiki/{$qid}");
            $title = (string) ($hit['label'] ?? $qid);
            $description = isset($hit['description']) ? (string) $hit['description'] : null;

            // Build a richer snippet if aliases exist
            $snippetParts = [];
            if ($description !== null && $description !== '') {
                $snippetParts[] = $description;
            }
            if (!empty($hit['aliases']) && is_array($hit['aliases'])) {
                $aliases = array_filter(array_map('strval', $hit['aliases']));
                if ($aliases !== []) {
                    $snippetParts[] = 'Also known as: ' . implode(', ', array_slice($aliases, 0, 3));
                }
            }
            $snippet = $snippetParts !== [] ? implode(' — ', $snippetParts) : null;

            $candidates[] = new CandidateResult(
                url:            $url,
                title:          $title,
                snippet:        $snippet,
                author:         null,
                sourcePlatform: 'Wikidata',
                publishedAt:    null, // Wikidata entries don't have a single creation date returned by this endpoint
                sentimentHint:  null,
                rawResponse:    $hit,
            );
        }

        return $candidates;
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
