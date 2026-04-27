<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianContentIndexerController
 *
 * ORBIT Phase 1 — brand content indexing.
 *
 * Endpoints:
 *   GET  /api/meridian/content-sources             — list sources for a brand
 *   POST /api/meridian/content-sources/create      — register a new source
 *   POST /api/meridian/content-sources/crawl       — trigger background crawl worker
 *   POST /api/meridian/content-sources/delete      — soft-delete a source
 *   GET  /api/meridian/content-items               — list crawled items
 *   GET  /api/meridian/content-items/detail        — single item detail
 *
 * Note: meridian_brand_content_sources HAS deleted_at (soft-delete supported).
 *       meridian_brand_content_items   does NOT (refreshed by crawl, not deleted).
 */
class MeridianContentIndexerController
{
    private const STATUS_PENDING   = 'pending';
    private const STATUS_CRAWLING  = 'crawling';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED    = 'failed';

    private const ALLOWED_SOURCE_TYPES = ['sitemap', 'feed', 'page_list', 'single_page'];

    // ── GET /api/meridian/content-sources?brand_id=X ─────────────
    public function listSources(): void
    {
        $auth    = MeridianAuth::require('analyst');
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;

        if ($brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = $this->fetchBrandOrAbort($brandId, $auth);

        $sources = DB::table('meridian_brand_content_sources')
            ->where('brand_id', $brandId)
            ->whereNull('deleted_at')
            ->orderBy('id', 'asc')
            ->get();

        json_response([
            'brandId'   => $brandId,
            'brandName' => $brand->name,
            'sources'   => array_map(fn($s) => $this->shapeSource($s), $sources->all()),
        ]);
    }

    // ── POST /api/meridian/content-sources/create ────────────────
    public function createSource(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $brandId       = (int)($body['brand_id'] ?? 0);
        $sourceUrl     = trim((string)($body['source_url'] ?? ''));
        $sourceType    = strtolower(trim((string)($body['source_type'] ?? '')));
        $cadenceDays   = (int)($body['crawl_cadence_days'] ?? 30);

        if ($brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }
        if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            json_response(['error' => 'source_url must be a valid URL.']);
            return;
        }
        if (!in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
            http_response_code(400);
            json_response([
                'error'   => 'Invalid source_type.',
                'allowed' => self::ALLOWED_SOURCE_TYPES,
            ]);
            return;
        }
        if ($cadenceDays < 1 || $cadenceDays > 365) {
            http_response_code(400);
            json_response(['error' => 'crawl_cadence_days must be between 1 and 365.']);
            return;
        }

        $this->fetchBrandOrAbort($brandId, $auth);

        if ($sourceType !== 'sitemap') {
            log_error('[ORBIT] Non-sitemap source_type registered (Phase 1 worker will reject)', [
                'brand_id'    => $brandId,
                'source_type' => $sourceType,
            ]);
        }

        try {
            $sourceId = DB::table('meridian_brand_content_sources')->insertGetId([
                'brand_id'           => $brandId,
                'source_url'         => $sourceUrl,
                'source_type'        => $sourceType,
                'crawl_cadence_days' => $cadenceDays,
                'status'             => self::STATUS_PENDING,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            log_error('[ORBIT] createSource failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to create source.']);
            return;
        }

        $source = DB::table('meridian_brand_content_sources')->where('id', $sourceId)->first();

        http_response_code(201);
        json_response([
            'sourceId' => (int)$sourceId,
            'source'   => $this->shapeSource($source),
        ]);
    }

    // ── POST /api/meridian/content-sources/crawl ─────────────────
    public function triggerCrawl(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $sourceId = (int)($body['source_id'] ?? 0);
        if ($sourceId <= 0) {
            http_response_code(400);
            json_response(['error' => 'source_id is required.']);
            return;
        }

        $source = DB::table('meridian_brand_content_sources')
            ->where('id', $sourceId)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            http_response_code(404);
            json_response(['error' => 'Source not found.']);
            return;
        }

        $this->fetchBrandOrAbort((int)$source->brand_id, $auth);

        if ($source->status === self::STATUS_CRAWLING) {
            http_response_code(409);
            json_response([
                'error'  => 'Crawl already in progress for this source.',
                'source' => $this->shapeSource($source),
            ]);
            return;
        }

        DB::table('meridian_brand_content_sources')
            ->where('id', $sourceId)
            ->update([
                'status'     => self::STATUS_CRAWLING,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        $workerScript = realpath(__DIR__ . '/../../workers/run_content_crawl.php');
        if ($workerScript && file_exists($workerScript)) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string)$sourceId)
                . ' > /dev/null 2>&1 &';
            exec($cmd);
        } else {
            DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->update([
                    'status'     => self::STATUS_FAILED,
                    'last_error' => 'Worker script not found on server.',
                    'updated_at' => now(),
                ]);
            log_error('[ORBIT] Worker script not found', [
                'expected' => __DIR__ . '/../../workers/run_content_crawl.php',
            ]);
            http_response_code(500);
            json_response(['error' => 'Worker script missing. Check deployment.']);
            return;
        }

        $fresh = DB::table('meridian_brand_content_sources')->where('id', $sourceId)->first();

        http_response_code(202);
        json_response([
            'status'   => 'queued',
            'sourceId' => $sourceId,
            'source'   => $this->shapeSource($fresh),
        ]);
    }

    // ── POST /api/meridian/content-sources/delete ────────────────
    public function deleteSource(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $sourceId = (int)($body['source_id'] ?? 0);
        if ($sourceId <= 0) {
            http_response_code(400);
            json_response(['error' => 'source_id is required.']);
            return;
        }

        $source = DB::table('meridian_brand_content_sources')
            ->where('id', $sourceId)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            http_response_code(404);
            json_response(['error' => 'Source not found.']);
            return;
        }

        $this->fetchBrandOrAbort((int)$source->brand_id, $auth);

        DB::table('meridian_brand_content_sources')
            ->where('id', $sourceId)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        json_response(['status' => 'deleted', 'sourceId' => $sourceId]);
    }

    // ── GET /api/meridian/content-items?source_id=X or ?brand_id=Y
    public function listItems(): void
    {
        $auth     = MeridianAuth::require('analyst');
        $sourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
        $brandId  = isset($_GET['brand_id'])  ? (int)$_GET['brand_id']  : 0;
        $limit    = max(1, min(500, (int)($_GET['limit']  ?? 50)));
        $offset   = max(0, (int)($_GET['offset'] ?? 0));

        if ($sourceId <= 0 && $brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'Either source_id or brand_id is required.']);
            return;
        }

        if ($sourceId > 0) {
            $source = DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->first();
            if (!$source) {
                http_response_code(404);
                json_response(['error' => 'Source not found.']);
                return;
            }
            $brandId = (int)$source->brand_id;
        }

        $this->fetchBrandOrAbort($brandId, $auth);

        // Note: items table has no deleted_at column — refreshed by crawl, not soft-deleted.
        $q = DB::table('meridian_brand_content_items')
            ->where('brand_id', $brandId);

        if ($sourceId > 0) {
            $q->where('source_id', $sourceId);
        }

        $total = (clone $q)->count();
        $items = $q->orderBy('id', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        json_response([
            'brandId'  => $brandId,
            'sourceId' => $sourceId ?: null,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'items'    => array_map(fn($i) => $this->shapeItem($i, false), $items->all()),
        ]);
    }

    // ── GET /api/meridian/content-items/detail?id=X ──────────────
    public function itemDetail(): void
    {
        $auth   = MeridianAuth::require('analyst');
        $itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($itemId <= 0) {
            http_response_code(400);
            json_response(['error' => 'id is required.']);
            return;
        }

        // Note: items table has no deleted_at column.
        $item = DB::table('meridian_brand_content_items')
            ->where('id', $itemId)
            ->first();

        if (!$item) {
            http_response_code(404);
            json_response(['error' => 'Item not found.']);
            return;
        }

        $this->fetchBrandOrAbort((int)$item->brand_id, $auth);

        json_response(['item' => $this->shapeItem($item, true)]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function fetchBrandOrAbort(int $brandId, MeridianAuth $auth): object
    {
        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found.']);
            exit;
        }

        if (!$auth->is_superadmin && (int)$brand->agency_id !== $auth->agency_id) {
            http_response_code(403);
            json_response(['error' => 'Brand not in your agency.']);
            exit;
        }

        return $brand;
    }

    private function shapeSource(object $s): array
    {
        return [
            'id'                 => (int)$s->id,
            'brandId'            => (int)$s->brand_id,
            'sourceUrl'          => $s->source_url,
            'sourceType'         => $s->source_type,
            'status'             => $s->status,
            'crawlCadenceDays'   => (int)$s->crawl_cadence_days,
            'lastCrawledAt'      => $s->last_crawled_at,
            'nextCrawlAt'        => $s->next_crawl_at,
            'itemsIndexed'       => isset($s->items_indexed) ? (int)$s->items_indexed : 0,
            'lastError'          => $s->last_error,
            'createdAt'          => $s->created_at,
            'updatedAt'          => $s->updated_at,
        ];
    }

    private function shapeItem(object $i, bool $full): array
    {
        $base = [
            'id'                   => (int)$i->id,
            'brandId'              => (int)$i->brand_id,
            'sourceId'             => (int)$i->source_id,
            'url'                  => $i->url,
            'urlCanonical'         => $i->url_canonical,
            'title'                => $i->title,
            'publishedAt'          => $i->published_at,
            'language'             => $i->language,
            'contentHash'          => $i->content_hash,
            'contentTextLength'    => isset($i->content_text) ? strlen((string)$i->content_text) : 0,
            'embeddingStatus'      => $i->embedding_status ?? null,
            'classificationStatus' => $i->classification_status ?? null,
            'crawledAt'            => $i->crawled_at ?? null,
            'createdAt'            => $i->created_at,
            'updatedAt'            => $i->updated_at,
        ];

        if ($full) {
            $base['contentText'] = $i->content_text ?? null;
            $base['contentHtml'] = $i->content_html ?? null;
        }

        return $base;
    }
}
