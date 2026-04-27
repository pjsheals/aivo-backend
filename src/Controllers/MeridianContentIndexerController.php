<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianContentCrawler;
use Aivo\Meridian\MeridianContentEmbedder;
use Aivo\Meridian\MeridianContentClassifier;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianContentIndexerController
 *
 * ORBIT Phase 1 — brand content indexing.
 *
 * Endpoints:
 *   GET  /api/meridian/content-sources                       — list sources for a brand
 *   POST /api/meridian/content-sources/create                — register a new source
 *   POST /api/meridian/content-sources/crawl                 — fire-and-forget background crawl
 *   POST /api/meridian/content-sources/debug-crawl-sync      — DIAGNOSTIC: synchronous crawl
 *   POST /api/meridian/content-sources/delete                — soft-delete a source
 *   GET  /api/meridian/content-items                         — list crawled items
 *   GET  /api/meridian/content-items/detail                  — single item detail
 *   POST /api/meridian/content-items/embed                   — fire-and-forget background embed
 *   POST /api/meridian/content-items/debug-embed-sync        — DIAGNOSTIC: synchronous embed
 *   POST /api/meridian/content-items/classify                — fire-and-forget background classify
 *   POST /api/meridian/content-items/debug-classify-sync     — DIAGNOSTIC: synchronous classify
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

    // Match the SQL CHECK constraint on meridian_brand_content_sources.source_type.
    // Phase 1 only implements 'sitemap' in the worker.
    private const ALLOWED_SOURCE_TYPES = ['sitemap', 'content_hub', 'rss', 'knowledge_base', 'document_repo'];

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

        try {
            $sourceId = DB::table('meridian_brand_content_sources')->insertGetId([
                'brand_id'           => $brandId,
                'source_url'         => $sourceUrl,
                'source_type'        => $sourceType,
                'crawl_cadence_days' => $cadenceDays,
                'status'             => self::STATUS_PENDING,
                'is_active'          => true,
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

    // ── POST /api/meridian/content-sources/debug-crawl-sync ──────
    public function debugCrawlSync(): void
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
                'status'     => self::STATUS_CRAWLING,
                'last_error' => null,
                'is_active'  => true,
                'updated_at' => now(),
            ]);

        $source = DB::table('meridian_brand_content_sources')->where('id', $sourceId)->first();

        @set_time_limit(180);
        @ini_set('memory_limit', '512M');

        $startedAt = microtime(true);

        try {
            $crawler = new MeridianContentCrawler($sourceId);
            $stats   = $crawler->run();

            $elapsedSec   = round(microtime(true) - $startedAt, 2);
            $totalIndexed = (int)($stats['indexed'] ?? 0) + (int)($stats['skipped'] ?? 0);

            $cadenceDays = (int)($source->crawl_cadence_days ?: 30);
            $nextCrawl   = $cadenceDays > 0
                ? date('Y-m-d H:i:s', time() + ($cadenceDays * 86400))
                : null;

            DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->update([
                    'status'          => self::STATUS_COMPLETED,
                    'last_crawled_at' => now(),
                    'next_crawl_at'   => $nextCrawl,
                    'items_indexed'   => $totalIndexed,
                    'last_error'      => null,
                    'updated_at'      => now(),
                ]);

            json_response([
                'status'       => 'completed',
                'sourceId'     => $sourceId,
                'elapsedSec'   => $elapsedSec,
                'stats'        => $stats,
                'totalIndexed' => $totalIndexed,
            ]);
        } catch (\Throwable $e) {
            $elapsedSec = round(microtime(true) - $startedAt, 2);
            $msg        = $e->getMessage();

            DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->update([
                    'status'     => self::STATUS_FAILED,
                    'last_error' => mb_substr($msg, 0, 1000),
                    'updated_at' => now(),
                ]);

            http_response_code(500);
            json_response([
                'status'     => 'failed',
                'sourceId'   => $sourceId,
                'elapsedSec' => $elapsedSec,
                'error'      => $msg,
                'trace'      => $e->getTraceAsString(),
            ]);
        }
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

    // ── POST /api/meridian/content-items/embed ───────────────────
    public function triggerEmbed(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $brandId  = (int)($body['brand_id'] ?? 0);
        $sourceId = isset($body['source_id']) ? (int)$body['source_id'] : null;

        if ($brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $this->fetchBrandOrAbort($brandId, $auth);

        if ($sourceId !== null) {
            $source = DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->first();
            if (!$source || (int)$source->brand_id !== $brandId) {
                http_response_code(404);
                json_response(['error' => 'Source not found for this brand.']);
                return;
            }
        }

        $pendingCount = DB::table('meridian_brand_content_items')
            ->where('brand_id', $brandId)
            ->whereNull('embedding')
            ->whereNotNull('content_text')
            ->when($sourceId !== null, fn($q) => $q->where('source_id', $sourceId))
            ->count();

        if ($pendingCount === 0) {
            json_response([
                'status'   => 'nothing_to_embed',
                'brandId'  => $brandId,
                'sourceId' => $sourceId,
            ]);
            return;
        }

        $workerScript = realpath(__DIR__ . '/../../workers/run_content_embed.php');
        if ($workerScript && file_exists($workerScript)) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string)$brandId);
            if ($sourceId !== null) {
                $cmd .= ' ' . escapeshellarg((string)$sourceId);
            }
            $cmd .= ' > /dev/null 2>&1 &';
            exec($cmd);
        } else {
            log_error('[ORBIT] Embedder worker script not found', [
                'expected' => __DIR__ . '/../../workers/run_content_embed.php',
            ]);
            http_response_code(500);
            json_response(['error' => 'Embedder worker script missing. Check deployment.']);
            return;
        }

        http_response_code(202);
        json_response([
            'status'       => 'queued',
            'brandId'      => $brandId,
            'sourceId'     => $sourceId,
            'pendingCount' => $pendingCount,
        ]);
    }

    // ── POST /api/meridian/content-items/debug-embed-sync ────────
    public function debugEmbedSync(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $brandId  = (int)($body['brand_id'] ?? 0);
        $sourceId = isset($body['source_id']) ? (int)$body['source_id'] : null;

        if ($brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $this->fetchBrandOrAbort($brandId, $auth);

        if ($sourceId !== null) {
            $source = DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->first();
            if (!$source || (int)$source->brand_id !== $brandId) {
                http_response_code(404);
                json_response(['error' => 'Source not found for this brand.']);
                return;
            }
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $startedAt = microtime(true);

        try {
            $embedder = new MeridianContentEmbedder($brandId, $sourceId);
            $stats    = $embedder->run();

            $elapsedSec = round(microtime(true) - $startedAt, 2);

            json_response([
                'status'     => 'completed',
                'brandId'    => $brandId,
                'sourceId'   => $sourceId,
                'elapsedSec' => $elapsedSec,
                'stats'      => $stats,
            ]);
        } catch (\Throwable $e) {
            $elapsedSec = round(microtime(true) - $startedAt, 2);
            http_response_code(500);
            json_response([
                'status'     => 'failed',
                'brandId'    => $brandId,
                'sourceId'   => $sourceId,
                'elapsedSec' => $elapsedSec,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }

    // ── POST /api/meridian/content-items/classify ────────────────
    // Body: { brand_id, source_id? }
    // Fire-and-forget background classifier.
    public function triggerClassify(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $brandId  = (int)($body['brand_id'] ?? 0);
        $sourceId = isset($body['source_id']) ? (int)$body['source_id'] : null;

        if ($brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $this->fetchBrandOrAbort($brandId, $auth);

        if ($sourceId !== null) {
            $source = DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->first();
            if (!$source || (int)$source->brand_id !== $brandId) {
                http_response_code(404);
                json_response(['error' => 'Source not found for this brand.']);
                return;
            }
        }

        $pendingCount = DB::table('meridian_brand_content_items')
            ->where('brand_id', $brandId)
            ->whereNull('classified_at')
            ->whereNotNull('content_text')
            ->when($sourceId !== null, fn($q) => $q->where('source_id', $sourceId))
            ->count();

        if ($pendingCount === 0) {
            json_response([
                'status'   => 'nothing_to_classify',
                'brandId'  => $brandId,
                'sourceId' => $sourceId,
            ]);
            return;
        }

        $workerScript = realpath(__DIR__ . '/../../workers/run_content_classify.php');
        if ($workerScript && file_exists($workerScript)) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string)$brandId);
            if ($sourceId !== null) {
                $cmd .= ' ' . escapeshellarg((string)$sourceId);
            }
            $cmd .= ' > /dev/null 2>&1 &';
            exec($cmd);
        } else {
            log_error('[ORBIT] Classifier worker script not found', [
                'expected' => __DIR__ . '/../../workers/run_content_classify.php',
            ]);
            http_response_code(500);
            json_response(['error' => 'Classifier worker script missing. Check deployment.']);
            return;
        }

        http_response_code(202);
        json_response([
            'status'       => 'queued',
            'brandId'      => $brandId,
            'sourceId'     => $sourceId,
            'pendingCount' => $pendingCount,
        ]);
    }

    // ── POST /api/meridian/content-items/debug-classify-sync ─────
    // DIAGNOSTIC: synchronous classify. Use curl --max-time 600 for large brands.
    public function debugClassifySync(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $brandId  = (int)($body['brand_id'] ?? 0);
        $sourceId = isset($body['source_id']) ? (int)$body['source_id'] : null;

        if ($brandId <= 0) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $this->fetchBrandOrAbort($brandId, $auth);

        if ($sourceId !== null) {
            $source = DB::table('meridian_brand_content_sources')
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->first();
            if (!$source || (int)$source->brand_id !== $brandId) {
                http_response_code(404);
                json_response(['error' => 'Source not found for this brand.']);
                return;
            }
        }

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $startedAt = microtime(true);

        try {
            $classifier = new MeridianContentClassifier($brandId, $sourceId);
            $stats      = $classifier->run();

            $elapsedSec = round(microtime(true) - $startedAt, 2);

            json_response([
                'status'     => 'completed',
                'brandId'    => $brandId,
                'sourceId'   => $sourceId,
                'elapsedSec' => $elapsedSec,
                'stats'      => $stats,
            ]);
        } catch (\Throwable $e) {
            $elapsedSec = round(microtime(true) - $startedAt, 2);
            http_response_code(500);
            json_response([
                'status'     => 'failed',
                'brandId'    => $brandId,
                'sourceId'   => $sourceId,
                'elapsedSec' => $elapsedSec,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
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
            'id'               => (int)$s->id,
            'brandId'          => (int)$s->brand_id,
            'sourceUrl'        => $s->source_url,
            'sourceType'       => $s->source_type,
            'status'           => $s->status,
            'isActive'         => isset($s->is_active) ? (bool)$s->is_active : null,
            'crawlCadenceDays' => (int)$s->crawl_cadence_days,
            'lastCrawledAt'    => $s->last_crawled_at,
            'nextCrawlAt'      => $s->next_crawl_at,
            'itemsIndexed'     => isset($s->items_indexed) ? (int)$s->items_indexed : 0,
            'lastError'        => $s->last_error,
            'createdAt'        => $s->created_at,
            'updatedAt'        => $s->updated_at,
        ];
    }

    private function shapeItem(object $i, bool $full): array
    {
        // Items table has no created_at/updated_at — only first_indexed_at/last_indexed_at.
        $base = [
            'id'                    => (int)$i->id,
            'brandId'               => (int)$i->brand_id,
            'sourceId'              => (int)$i->source_id,
            'url'                   => $i->url,
            'urlCanonical'          => $i->url_canonical,
            'title'                 => $i->title,
            'contentDate'           => $i->content_date ?? null,
            'contentHtmlHash'       => $i->content_html_hash ?? null,
            'contentTextLength'     => isset($i->content_text) ? strlen((string)$i->content_text) : 0,
            'wordCount'             => isset($i->word_count) ? (int)$i->word_count : null,
            'hasEmbedding'          => isset($i->embedding) && $i->embedding !== null && $i->embedding !== '',
            'topics'                => $this->parsePgTextArray($i->topics ?? null),
            'subBrand'              => $i->sub_brand ?? null,
            'territory'             => $i->territory ?? null,
            'contentType'           => $i->content_type ?? null,
            'language'              => $i->language ?? null,
            'citationTierEstimate'  => $i->citation_tier_estimate ?? null,
            'hasData'               => isset($i->has_data) ? (bool)$i->has_data : null,
            'hasExternalCitations'  => isset($i->has_external_citations) ? (bool)$i->has_external_citations : null,
            'hasMethodology'        => isset($i->has_methodology) ? (bool)$i->has_methodology : null,
            'classifiedBy'          => $i->classified_by ?? null,
            'classifiedAt'          => $i->classified_at ?? null,
            'firstIndexedAt'        => $i->first_indexed_at ?? null,
            'lastIndexedAt'         => $i->last_indexed_at ?? null,
        ];

        if ($full) {
            $base['contentText']               = $i->content_text ?? null;
            $base['embeddingInputText']        = $i->embedding_input_text ?? null;
            $base['classificationConfidences'] = $this->parseJsonField($i->classification_confidences ?? null);
        }

        return $base;
    }

    /** Parse a Postgres TEXT[] column (e.g. '{"foo","bar"}') into a PHP array. */
    private function parsePgTextArray($value): array
    {
        if ($value === null || $value === '' || $value === '{}') return [];
        if (is_array($value)) return $value;

        // Strip outer braces, split on comma, unquote each element.
        $inner = trim((string)$value, '{}');
        if ($inner === '') return [];

        // Simple split — items contain quoted strings; handle commas inside via quote tracking.
        $items = [];
        $current = '';
        $inQuotes = false;
        $escape = false;
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $ch = $inner[$i];
            if ($escape) { $current .= $ch; $escape = false; continue; }
            if ($ch === '\\') { $escape = true; continue; }
            if ($ch === '"') { $inQuotes = !$inQuotes; continue; }
            if ($ch === ',' && !$inQuotes) { $items[] = $current; $current = ''; continue; }
            $current .= $ch;
        }
        if ($current !== '') $items[] = $current;

        return $items;
    }

    private function parseJsonField($value): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
