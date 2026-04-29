<?php

declare(strict_types=1);

namespace Aivo\Orbit\Services;

use Aivo\Orbit\Contracts\SearchProviderInterface;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * CitationPlatformResolver — single point of access to citation_platforms.
 *
 * Two roles:
 *   1. Search routing: given requested tiers + sectors, return which searchable
 *      platforms the orchestrator should query.
 *   2. URL classification: given a result URL (or its domain), return the
 *      citation_platforms row that best matches — for tier/score lookup.
 *
 * Platform rows are cached for the lifetime of one PHP request. Tables are
 * small (~200-300 rows) so loading the whole set up-front is cheap.
 *
 * Uses Illuminate\Database\Capsule\Manager — same pattern as
 * AdminController/OptimizeController in aivo-backend.
 */
final class CitationPlatformResolver
{
    /** @var array<int, array> */
    private array $allPlatforms = [];
    private bool  $loaded = false;

    /** @var array<string, int> domain → platform id */
    private array $domainIndex = [];

    /** @var int|null Fallback T3.9 platform id (the pattern='.*' row) */
    private ?int $fallbackId = null;

    /**
     * Pick searchable platforms for a search run.
     *
     * @param string[] $requestedTiers e.g. ['T1.2','T1.3','T2.4']. If empty, all tiers allowed.
     * @param string[] $brandSectors   e.g. ['beauty']. Used to prioritise sector-matched platforms.
     *
     * @return array<int, array> Platform rows, ordered: API > site_search > Brave fallback.
     */
    public function pickSearchablePlatforms(array $requestedTiers, array $brandSectors): array
    {
        $this->ensureLoaded();

        $requestedTiers = array_map('strval', $requestedTiers);
        $brandSectors   = array_map(static fn ($s) => strtolower((string) $s), $brandSectors);

        $picked = [];
        foreach ($this->allPlatforms as $row) {
            if (!$row['searchable']) {
                continue;
            }
            if ($row['deprecated_at'] !== null) {
                continue;
            }
            $tier = (string) ($row['tier'] ?? '');
            if ($requestedTiers !== [] && !$this->tierMatches($tier, $requestedTiers)) {
                continue;
            }
            $picked[] = $row;
        }

        // Sort: api > site_search > google_site > sitemap_crawl, then sector-matched first, then by tier
        usort($picked, function (array $a, array $b) use ($brandSectors): int {
            $methodOrder = ['api' => 0, 'site_search' => 1, 'google_site' => 2, 'sitemap_crawl' => 3];
            $am = $methodOrder[$a['search_method'] ?? ''] ?? 99;
            $bm = $methodOrder[$b['search_method'] ?? ''] ?? 99;
            if ($am !== $bm) {
                return $am <=> $bm;
            }
            $aSectorMatch = $this->hasSectorOverlap($a['sector'] ?? [], $brandSectors);
            $bSectorMatch = $this->hasSectorOverlap($b['sector'] ?? [], $brandSectors);
            if ($aSectorMatch !== $bSectorMatch) {
                return $bSectorMatch <=> $aSectorMatch; // matched first
            }
            $at = (string) ($a['tier'] ?? 'T9.9');
            $bt = (string) ($b['tier'] ?? 'T9.9');
            return strcmp($at, $bt);
        });

        return $picked;
    }

    /**
     * Classify a URL against citation_platforms by domain, returning a row or
     * the T3.9 fallback when nothing matches.
     */
    public function classifyUrl(string $url): array
    {
        $this->ensureLoaded();

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $this->fallbackRow();
        }
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Direct domain hit
        if (isset($this->domainIndex[$host])) {
            return $this->allPlatforms[$this->domainIndex[$host]];
        }

        // Domain suffix match — e.g. 'subdomain.medium.com' matches 'medium.com'
        $parts = explode('.', $host);
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $suffix = implode('.', array_slice($parts, $i));
            if (isset($this->domainIndex[$suffix])) {
                return $this->allPlatforms[$this->domainIndex[$suffix]];
            }
        }

        return $this->fallbackRow();
    }

    /**
     * Map a SearchProviderInterface name back to its citation_platforms row.
     */
    public function findByProviderName(SearchProviderInterface $provider): ?array
    {
        $this->ensureLoaded();
        $providerName = strtolower($provider->getName());

        foreach ($this->allPlatforms as $row) {
            $platformName = strtolower((string) ($row['platform_name'] ?? ''));
            if ($platformName === $providerName) {
                return $row;
            }
        }
        return null;
    }

    private function fallbackRow(): array
    {
        $this->ensureLoaded();
        if ($this->fallbackId !== null && isset($this->allPlatforms[$this->fallbackId])) {
            return $this->allPlatforms[$this->fallbackId];
        }
        // Synthetic T3.9 fallback if seed somehow missing
        return [
            'id'              => null,
            'platform_name'   => 'General Web (fallback)',
            'tier'            => 'T3.9',
            'score_base'      => 15,
            'sector'          => [],
            'tags'            => ['fallback'],
            'searchable'      => false,
            'search_method'   => null,
            'deprecated_at'   => null,
        ];
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $rows = Capsule::table('citation_platforms')
            ->select(
                'id',
                'platform_name',
                'domain',
                'pattern',
                'tier',
                'score_base',
                'sector',
                'tags',
                'searchable',
                'search_method',
                'search_endpoint',
                'api_auth_type',
                'rate_limit_qpm',
                'cost_per_query',
                'typical_recency',
                'sentiment_relevance',
                'deprecated_at',
                'notes'
            )
            ->orderBy('id', 'asc')
            ->get();

        foreach ($rows as $r) {
            // Capsule returns stdClass objects; convert to assoc array
            $row = (array) $r;

            // Postgres TEXT[] arrives as a string like {a,b,c}; normalise to PHP array
            $row['sector']        = $this->parsePgArray($row['sector'] ?? null);
            $row['tags']          = $this->parsePgArray($row['tags'] ?? null);
            $row['searchable']    = $this->parseBool($row['searchable'] ?? false);
            $row['score_base']    = (int) ($row['score_base'] ?? 15);
            $row['deprecated_at'] = $row['deprecated_at'] ?? null;

            $id = (int) $row['id'];
            $row['id'] = $id;
            $this->allPlatforms[$id] = $row;

            if (!empty($row['domain'])) {
                $domain = strtolower((string) $row['domain']);
                if (str_starts_with($domain, 'www.')) {
                    $domain = substr($domain, 4);
                }
                $this->domainIndex[$domain] = $id;
            }

            // Identify the T3.9 fallback (pattern '.*' with NULL domain)
            if (
                $this->fallbackId === null
                && empty($row['domain'])
                && (string) ($row['pattern'] ?? '') === '.*'
            ) {
                $this->fallbackId = $id;
            }
        }
    }

    private function tierMatches(string $tier, array $requestedTiers): bool
    {
        if (in_array($tier, $requestedTiers, true)) {
            return true;
        }
        foreach ($requestedTiers as $rt) {
            if (str_ends_with($rt, '.*')) {
                $prefix = rtrim($rt, '.*');
                if (str_starts_with($tier, $prefix)) {
                    return true;
                }
            } elseif (preg_match('/^T\d+$/', $rt) && str_starts_with($tier, $rt . '.')) {
                return true;
            }
        }
        return false;
    }

    private function hasSectorOverlap($platformSectors, array $brandSectors): bool
    {
        $platformSectors = is_array($platformSectors)
            ? array_map(static fn ($s) => strtolower((string) $s), $platformSectors)
            : [];

        if ($platformSectors === []) {
            return true; // empty = applies to all
        }
        foreach ($brandSectors as $b) {
            if (in_array($b, $platformSectors, true)) {
                return true;
            }
        }
        return false;
    }

    private function parsePgArray($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn ($v) => $v !== ''));
        }
        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }
        $inner = trim($value, '{}');
        if ($inner === '') {
            return [];
        }
        $parts = str_getcsv($inner);
        return array_values(array_filter(array_map(static function ($p) {
            return trim((string) $p, '"');
        }, $parts), static fn ($v) => $v !== ''));
    }

    private function parseBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower((string) $value);
        return in_array($s, ['t', 'true', '1', 'yes'], true);
    }
}
