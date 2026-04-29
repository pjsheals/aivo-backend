<?php

declare(strict_types=1);

namespace Aivo\Orbit\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Throwable;

/**
 * OrbitAdminController — superadmin-only CRUD on citation_platforms.
 *
 * Superadmins (paul@aivoedge.net, tim@aivoedge.net, paul@aivoevidentia.com)
 * can list, create, update, and soft-delete (deprecate) citation platforms
 * from the Meridian admin UI rather than hand-editing SQL.
 *
 * Routes:
 *   GET    /api/orbit/admin/citation-platforms        — list (filters: tier, sector, searchable)
 *   POST   /api/orbit/admin/citation-platforms        — create
 *   GET    /api/orbit/admin/citation-platforms/{id}   — show one
 *   PATCH  /api/orbit/admin/citation-platforms/{id}   — update
 *   DELETE /api/orbit/admin/citation-platforms/{id}   — soft-delete (sets deprecated_at)
 */
class OrbitAdminController
{
    /** @var string[] */
    private const VALID_SEARCH_METHODS = ['api', 'site_search', 'google_site', 'sitemap_crawl'];
    /** @var string[] */
    private const VALID_AUTH_TYPES     = ['none', 'api_key', 'header', 'oauth', 'bearer'];
    /** @var string[] */
    private const VALID_RECENCY        = ['live', 'days', 'weeks', 'months', 'years', 'static'];

    // -------------------------------------------------------------------------
    // GET /api/orbit/admin/citation-platforms
    // -------------------------------------------------------------------------
    public function listPlatforms(): void
    {
        $this->requireSuperadmin();

        $query = Capsule::table('citation_platforms');

        // Optional filters from query string
        if (!empty($_GET['tier'])) {
            $tier = (string) $_GET['tier'];
            // Support 'T1.*' wildcard — match the tier prefix
            if (str_ends_with($tier, '.*')) {
                $prefix = rtrim($tier, '.*');
                $query->where('tier', 'like', $prefix . '.%');
            } else {
                $query->where('tier', $tier);
            }
        }
        if (isset($_GET['searchable'])) {
            $query->where('searchable', filter_var($_GET['searchable'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($_GET['include_deprecated']) && filter_var($_GET['include_deprecated'], FILTER_VALIDATE_BOOLEAN)) {
            // include all
        } else {
            $query->whereNull('deprecated_at');
        }

        // Sector filter — uses GIN index on sector TEXT[]
        if (!empty($_GET['sector'])) {
            $sector = (string) $_GET['sector'];
            $query->whereRaw('? = ANY(sector)', [strtolower($sector)]);
        }

        $rows = $query->orderBy('tier', 'asc')->orderBy('platform_name', 'asc')->get();

        $payload = array_map([$this, 'rowToArray'], $rows->all());

        json_response([
            'success' => true,
            'count'   => count($payload),
            'platforms' => $payload,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/orbit/admin/citation-platforms
    // -------------------------------------------------------------------------
    public function createPlatform(): void
    {
        $this->requireSuperadmin();

        $body = $this->readJsonBody();
        try {
            $data = $this->validatePayload($body, true);
        } catch (Throwable $e) {
            http_response_code(400);
            json_response(['error' => $e->getMessage()]);
            return;
        }

        try {
            $id = $this->insertWithArrayColumns($data);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response(['error' => 'Insert failed: ' . $e->getMessage()]);
            return;
        }

        $row = Capsule::table('citation_platforms')->where('id', $id)->first();
        json_response([
            'success' => true,
            'platform' => $row ? $this->rowToArray($row) : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/orbit/admin/citation-platforms/{id}
    // -------------------------------------------------------------------------
    public function showPlatform(): void
    {
        $this->requireSuperadmin();

        $id = $this->extractIdFromUri('citation-platforms');
        if ($id <= 0) {
            http_response_code(400);
            json_response(['error' => 'Invalid platform id.']);
            return;
        }

        $row = Capsule::table('citation_platforms')->where('id', $id)->first();
        if (!$row) {
            http_response_code(404);
            json_response(['error' => "Platform {$id} not found."]);
            return;
        }

        json_response([
            'success'  => true,
            'platform' => $this->rowToArray($row),
        ]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/orbit/admin/citation-platforms/{id}
    // -------------------------------------------------------------------------
    public function updatePlatform(): void
    {
        $this->requireSuperadmin();

        $id = $this->extractIdFromUri('citation-platforms');
        if ($id <= 0) {
            http_response_code(400);
            json_response(['error' => 'Invalid platform id.']);
            return;
        }

        $existing = Capsule::table('citation_platforms')->where('id', $id)->first();
        if (!$existing) {
            http_response_code(404);
            json_response(['error' => "Platform {$id} not found."]);
            return;
        }

        $body = $this->readJsonBody();
        try {
            $data = $this->validatePayload($body, false);
        } catch (Throwable $e) {
            http_response_code(400);
            json_response(['error' => $e->getMessage()]);
            return;
        }

        if ($data === []) {
            http_response_code(400);
            json_response(['error' => 'No valid fields supplied to update.']);
            return;
        }

        try {
            $this->updateWithArrayColumns($id, $data);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response(['error' => 'Update failed: ' . $e->getMessage()]);
            return;
        }

        $row = Capsule::table('citation_platforms')->where('id', $id)->first();
        json_response([
            'success' => true,
            'platform' => $row ? $this->rowToArray($row) : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/orbit/admin/citation-platforms/{id}
    // -------------------------------------------------------------------------
    public function deletePlatform(): void
    {
        $this->requireSuperadmin();

        $id = $this->extractIdFromUri('citation-platforms');
        if ($id <= 0) {
            http_response_code(400);
            json_response(['error' => 'Invalid platform id.']);
            return;
        }

        $existing = Capsule::table('citation_platforms')->where('id', $id)->first();
        if (!$existing) {
            http_response_code(404);
            json_response(['error' => "Platform {$id} not found."]);
            return;
        }

        // Soft-delete: set deprecated_at; never hard-delete because results in
        // orbit_search_results reference platform_id.
        Capsule::table('citation_platforms')
            ->where('id', $id)
            ->update(['deprecated_at' => Capsule::raw('NOW()')]);

        json_response([
            'success' => true,
            'platform_id' => $id,
            'deprecated' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function requireSuperadmin(): void
    {
        $auth = MeridianAuth::require('admin');
        if (!$auth->is_superadmin) {
            http_response_code(403);
            json_response(['error' => 'Superadmin only.']);
            exit;
        }
    }

    /**
     * Validate and shape an incoming payload into something safe to insert/update.
     * Returns only fields the caller actually supplied (so PATCH partial updates work).
     */
    private function validatePayload(array $body, bool $isCreate): array
    {
        $out = [];

        $strFields = [
            'platform_name'   => 255,
            'domain'          => 255,
            'pattern'         => 500,
            'tier'            => 10,
            'search_method'   => 20,
            'search_endpoint' => 500,
            'api_auth_type'   => 20,
            'typical_recency' => 20,
            'notes'           => null,
        ];
        foreach ($strFields as $k => $maxLen) {
            if (array_key_exists($k, $body)) {
                $v = $body[$k] === null ? null : trim((string) $body[$k]);
                if ($v !== null && $maxLen !== null && mb_strlen($v) > $maxLen) {
                    throw new \InvalidArgumentException("{$k} exceeds {$maxLen} characters.");
                }
                $out[$k] = $v === '' ? null : $v;
            }
        }

        if (array_key_exists('score_base', $body)) {
            $score = (int) $body['score_base'];
            if ($score < 0 || $score > 100) {
                throw new \InvalidArgumentException('score_base must be between 0 and 100.');
            }
            $out['score_base'] = $score;
        }

        if (array_key_exists('searchable', $body)) {
            $out['searchable'] = (bool) $body['searchable'];
        }

        if (array_key_exists('sentiment_relevance', $body)) {
            $out['sentiment_relevance'] = (bool) $body['sentiment_relevance'];
        }

        if (array_key_exists('rate_limit_qpm', $body)) {
            $out['rate_limit_qpm'] = $body['rate_limit_qpm'] === null
                ? null : max(0, (int) $body['rate_limit_qpm']);
        }

        if (array_key_exists('cost_per_query', $body)) {
            $out['cost_per_query'] = $body['cost_per_query'] === null
                ? null : (float) $body['cost_per_query'];
        }

        // sector and tags are PG TEXT[] — accept arrays of strings
        if (array_key_exists('sector', $body)) {
            $out['sector'] = $this->normaliseStringArray($body['sector']);
        }
        if (array_key_exists('tags', $body)) {
            $out['tags'] = $this->normaliseStringArray($body['tags']);
        }

        // Validation against CHECK constraints
        if (isset($out['search_method']) && !in_array($out['search_method'], self::VALID_SEARCH_METHODS, true)) {
            throw new \InvalidArgumentException(
                'search_method must be one of: ' . implode(', ', self::VALID_SEARCH_METHODS)
            );
        }
        if (isset($out['api_auth_type']) && !in_array($out['api_auth_type'], self::VALID_AUTH_TYPES, true)) {
            throw new \InvalidArgumentException(
                'api_auth_type must be one of: ' . implode(', ', self::VALID_AUTH_TYPES)
            );
        }
        if (isset($out['typical_recency']) && !in_array($out['typical_recency'], self::VALID_RECENCY, true)) {
            throw new \InvalidArgumentException(
                'typical_recency must be one of: ' . implode(', ', self::VALID_RECENCY)
            );
        }

        // CREATE-only required fields
        if ($isCreate) {
            $required = ['platform_name', 'tier', 'score_base'];
            foreach ($required as $r) {
                if (empty($out[$r]) && $out[$r] !== 0 && $out[$r] !== '0') {
                    throw new \InvalidArgumentException("Field '{$r}' is required for create.");
                }
            }
            // domain or pattern required (CHECK constraint)
            if (empty($out['domain']) && empty($out['pattern'])) {
                throw new \InvalidArgumentException(
                    'Either domain or pattern must be supplied (CHECK constraint citation_platforms_id_required).'
                );
            }
            // Sensible defaults for CREATE
            $out['searchable'] = $out['searchable'] ?? false;
            $out['sentiment_relevance'] = $out['sentiment_relevance'] ?? true;
            $out['sector'] = $out['sector'] ?? [];
            $out['tags'] = $out['tags'] ?? [];
        }

        return $out;
    }

    private function normaliseStringArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Insert handling PG TEXT[] columns via raw expressions (Capsule doesn't
     * auto-convert PHP arrays).
     */
    private function insertWithArrayColumns(array $data): int
    {
        $insert = $this->buildArrayAwarePayload($data);
        return (int) Capsule::table('citation_platforms')->insertGetId($insert);
    }

    private function updateWithArrayColumns(int $id, array $data): void
    {
        $update = $this->buildArrayAwarePayload($data);
        $update['updated_at'] = Capsule::raw('NOW()');
        Capsule::table('citation_platforms')->where('id', $id)->update($update);
    }

    private function buildArrayAwarePayload(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($k === 'sector' || $k === 'tags') {
                if (is_array($v)) {
                    if ($v === []) {
                        $out[$k] = Capsule::raw("'{}'::text[]");
                    } else {
                        $out[$k] = Capsule::raw($this->phpArrayToPgArrayLiteral($v));
                    }
                }
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Convert ['a','b','c with "quotes"'] to ARRAY['a','b','c with "quotes"']::text[]
     */
    private function phpArrayToPgArrayLiteral(array $values): string
    {
        $escaped = array_map(static function ($v) {
            $s = (string) $v;
            $s = str_replace("'", "''", $s);
            return "'{$s}'";
        }, $values);
        return 'ARRAY[' . implode(',', $escaped) . ']::text[]';
    }

    /**
     * Convert a Capsule stdClass row into a clean assoc array suitable for JSON.
     */
    private function rowToArray($row): array
    {
        $r = (array) $row;
        $r['sector']     = $this->parsePgArray($r['sector']     ?? null);
        $r['tags']       = $this->parsePgArray($r['tags']       ?? null);
        $r['searchable'] = $this->parseBool($r['searchable'] ?? false);
        $r['sentiment_relevance'] = $this->parseBool($r['sentiment_relevance'] ?? true);
        $r['score_base'] = isset($r['score_base']) ? (int) $r['score_base'] : null;
        $r['id']         = isset($r['id']) ? (int) $r['id'] : null;
        return $r;
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

    private function extractIdFromUri(string $segment): int
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $parts = array_values(array_filter(explode('/', $uri), static fn ($p) => $p !== ''));
        foreach ($parts as $idx => $p) {
            if ($p === $segment && isset($parts[$idx + 1])) {
                $next = $parts[$idx + 1];
                if (ctype_digit($next)) {
                    return (int) $next;
                }
            }
        }
        return 0;
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
