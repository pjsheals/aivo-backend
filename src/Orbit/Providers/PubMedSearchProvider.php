<?php

declare(strict_types=1);

namespace Aivo\Orbit\Providers;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Aivo\Orbit\DTO\CandidateResult;
use Aivo\Orbit\Exceptions\SearchProviderException;
use DateTimeImmutable;
use Throwable;

/**
 * PubMed search adapter using NCBI E-utilities.
 *
 * T1.2 peer-reviewed academic, focused on medical/biomedical literature.
 * Free, no auth required.
 *
 * Two-step: ESearch → ESummary
 *   1. ESearch returns matching PMIDs
 *   2. ESummary returns metadata for each PMID
 *
 * Rate limit: 3 req/sec without API key, 10 req/sec with.
 *
 * Docs: https://www.ncbi.nlm.nih.gov/books/NBK25500/
 *
 * Options:
 *   - 'count' (int): max results (default 10, max 100)
 *   - 'sort'  (string): 'relevance' (default) | 'pub+date' | 'most+recent'
 */
final class PubMedSearchProvider implements SearchProviderInterface
{
    private const ESEARCH_URL  = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi';
    private const ESUMMARY_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi';

    private const DEFAULT_COUNT   = 10;
    private const MAX_COUNT       = 100;
    private const TIMEOUT_SECONDS = 12;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0 (https://aivoedge.net; mailto:paul@aivoedge.net)';

    public function getName(): string
    {
        return 'pubmed';
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $count = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? self::DEFAULT_COUNT)));
        $sort  = $this->validateSort($options['sort'] ?? 'relevance');

        // Step 1 — ESearch for PMIDs
        $searchParams = [
            'db'      => 'pubmed',
            'term'    => $query,
            'retmode' => 'json',
            'retmax'  => $count,
            'sort'    => $sort,
            'tool'    => 'aivo-orbit',
            'email'   => 'paul@aivoedge.net',
        ];

        [$body, $httpCode, $err] = $this->httpGet(self::ESEARCH_URL . '?' . http_build_query($searchParams));
        if ($body === null) {
            throw new SearchProviderException("PubMed ESearch failed: {$err}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException("PubMed ESearch returned HTTP {$httpCode}: " . substr($body, 0, 300));
        }

        $data  = json_decode($body, true);
        $pmids = $data['esearchresult']['idlist'] ?? null;
        if (!is_array($pmids) || $pmids === []) {
            return [];
        }

        // Step 2 — ESummary for metadata
        $summaryParams = [
            'db'      => 'pubmed',
            'id'      => implode(',', array_slice($pmids, 0, $count)),
            'retmode' => 'json',
            'tool'    => 'aivo-orbit',
            'email'   => 'paul@aivoedge.net',
        ];

        [$body2, $httpCode2, $err2] = $this->httpGet(self::ESUMMARY_URL . '?' . http_build_query($summaryParams));
        if ($body2 === null) {
            throw new SearchProviderException("PubMed ESummary failed: {$err2}");
        }
        if ($httpCode2 < 200 || $httpCode2 >= 300) {
            throw new SearchProviderException("PubMed ESummary returned HTTP {$httpCode2}: " . substr($body2, 0, 300));
        }

        $summaryData = json_decode($body2, true);
        $records     = $summaryData['result'] ?? null;
        if (!is_array($records)) {
            return [];
        }

        $candidates = [];
        foreach ($pmids as $pmid) {
            $pmid = (string) $pmid;
            $rec  = $records[$pmid] ?? null;
            if (!is_array($rec)) {
                continue;
            }
            $candidate = $this->mapRecord($pmid, $rec);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function mapRecord(string $pmid, array $rec): ?CandidateResult
    {
        $url   = "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/";
        $title = isset($rec['title']) ? trim((string) $rec['title']) : '';

        // Authors are an array of {name, authtype, ...}
        $authorNames = [];
        if (isset($rec['authors']) && is_array($rec['authors'])) {
            foreach ($rec['authors'] as $a) {
                if (is_array($a) && isset($a['name']) && is_string($a['name'])) {
                    $authorNames[] = $a['name'];
                }
            }
        }
        $authorString = null;
        if ($authorNames !== []) {
            $authorString = count($authorNames) > 3
                ? implode(', ', array_slice($authorNames, 0, 3)) . ' et al.'
                : implode(', ', $authorNames);
        }

        $journal  = isset($rec['fulljournalname']) ? (string) $rec['fulljournalname'] : (
            isset($rec['source']) ? (string) $rec['source'] : null
        );
        $pubType = null;
        if (!empty($rec['pubtype']) && is_array($rec['pubtype']) && $rec['pubtype'] !== []) {
            $pubType = (string) $rec['pubtype'][0];
        }

        $bits = array_filter([$journal, $pubType, "PMID: {$pmid}"]);
        $snippet = $bits !== [] ? implode(' • ', $bits) : null;

        $publishedAt = null;
        if (!empty($rec['pubdate']) && is_string($rec['pubdate'])) {
            try {
                $publishedAt = new DateTimeImmutable($rec['pubdate']);
            } catch (Throwable) {
                // PubMed sometimes returns "2024 Mar" or "2024" — try parsing those
                $year = preg_match('/\b(\d{4})\b/', $rec['pubdate'], $m) ? (int) $m[1] : null;
                if ($year !== null && $year >= 1900 && $year <= 2100) {
                    try {
                        $publishedAt = new DateTimeImmutable("{$year}-01-01T00:00:00Z");
                    } catch (Throwable) {
                        // give up gracefully
                    }
                }
            }
        }

        return new CandidateResult(
            url:            $url,
            title:          $title !== '' ? $title : null,
            snippet:        $snippet,
            author:         $authorString,
            sourcePlatform: 'PubMed',
            publishedAt:    $publishedAt,
            sentimentHint:  null,
            rawResponse:    $rec,
        );
    }

    private function validateSort(string $sort): string
    {
        $map = [
            'relevance'   => 'relevance',
            'pub+date'    => 'pub+date',
            'most+recent' => 'most+recent',
            'date'        => 'pub+date',
            'recent'      => 'most+recent',
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
