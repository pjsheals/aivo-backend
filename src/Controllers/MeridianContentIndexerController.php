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
 * ORBIT Phase 1 — brand content indexing (Radar UI).
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
 *   POST /api/meridian/content-items/correct                 — apply user correction + audit log
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

    private const ALLOWED_SOURCE_TYPES = ['sitemap', 'content_hub', 'rss', 'knowledge_base', 'document_repo'];

    // Match the SQL CHECK constraint on meridian_brand_content_classifications_log.axis.
    // Only these axes are user-correctable; T-tier and evidence signals are machine-only.
    private const CORRECTABLE_AXES = ['topics', 'sub_brand', 'territory', 'content_type', 'content_date', 'language'];

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

    // ── POST /api/meridian/content-items/correct ────────────────────
    // Body: { item_id, axis, new_value, reason? }
    //
    // Applies a user correction to one classification axis. Writes both:
    //   - items table (the corrected value)
    //   - classifications_log (audit trail of what changed and by whom)
    //
    // Only axes in CORRECTABLE_AXES are accepted (matches the SQL CHECK constraint
    // on meridian_brand_content_classifications_log.axis). T-tier and evidence
    // signals are machine-only — not user-correctable in Phase 1.
    public function correctClassification(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();

        $itemId   = (int)($body['item_id'] ?? 0);
        $axis     = strtolower(trim((string)($body['axis'] ?? '')));
        $reason   = trim((string)($body['reason'] ?? ''));
        $newValue = $body['new_value'] ?? null;  // can be string, array, or null

        if ($itemId <= 0) {
            http_response_code(400);
            json_response(['error' => 'item_id is required.']);
            return;
        }
        if (!in_array($axis, self::CORRECTABLE_AXES, true)) {
            http_response_code(400);
            json_response([
                'error'     => 'Axis is not user-correctable.',
                'allowed'   => self::CORRECTABLE_AXES,
                'requested' => $axis,
            ]);
            return;
        }

        $item = DB::table('meridian_brand_content_items')->where('id', $itemId)->first();
        if (!$item) {
            http_response_code(404);
            json_response(['error' => 'Item not found.']);
            return;
        }

        $this->fetchBrandOrAbort((int)$item->brand_id, $auth);

        // Capture previous value for audit trail (per-axis logic).
        $previousValue = match ($axis) {
            'topics'       => $this->parsePgTextArray($item->topics ?? null),
            'sub_brand'    => $item->sub_brand ?? null,
            'territory'    => $item->territory ?? null,
            'content_type' => $item->content_type ?? null,
            'content_date' => $item->content_date ?? null,
            'language'     => $item->language ?? null,
        };

        // Validate + normalise new value per axis.
        try {
            $normalised = $this->normaliseCorrectionValue($axis, $newValue);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            json_response(['error' => $e->getMessage()]);
            return;
        }

        // Write audit log row first — if the items update fails we still know what was attempted.
        // Schema columns (per migration 001):
        //   item_id, brand_id, user_id, axis, previous_value, new_value, rationale, created_at
        // brand_id is NOT NULL and denormalised onto the log table for fast brand-scoped audit queries.
        // The API parameter is 'reason' (user-friendly) but the column is 'rationale' (per schema).
        try {
            DB::table('meridian_brand_content_classifications_log')->insert([
                'item_id'        => $itemId,
                'brand_id'       => (int)$item->brand_id,
                'user_id'        => $auth->user_id ?? null,
                'axis'           => $axis,
                'previous_value' => json_encode($previousValue, JSON_UNESCAPED_SLASHES),
                'new_value'      => json_encode($normalised['logged'], JSON_UNESCAPED_SLASHES),
                'rationale'      => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            log_error('[ORBIT] correction audit log insert failed', [
                'item_id' => $itemId,
                'axis'    => $axis,
                'error'   => $e->getMessage(),
            ]);
            http_response_code(500);
            json_response(['error' => 'Failed to write audit log entry.']);
            return;
        }

        // Apply the correction to the items table.
        try {
            if ($axis === 'topics') {
                // TEXT[] needs explicit ::text[] cast (same pattern as classifier).
                DB::statement(
                    'UPDATE meridian_brand_content_items
                        SET topics = ?::text[]
                        WHERE id = ?',
                    [$this->toPgTextArray($normalised['stored']), $itemId]
                );
            } else {
                // Plain string columns — direct update is fine.
                DB::table('meridian_brand_content_items')
                    ->where('id', $itemId)
                    ->update([$axis => $normalised['stored']]);
            }
        } catch (\Throwable $e) {
            log_error('[ORBIT] correction items update failed', [
                'item_id' => $itemId,
                'axis'    => $axis,
                'error'   => $e->getMessage(),
            ]);
            http_response_code(500);
            json_response([
                'error' => 'Audit log written but items update failed. Item is in inconsistent state.',
                'detail' => $e->getMessage(),
            ]);
            return;
        }

        // Return refreshed item.
        $fresh = DB::table('meridian_brand_content_items')->where('id', $itemId)->first();
        json_response([
            'status'        => 'corrected',
            'itemId'        => $itemId,
            'axis'          => $axis,
            'previousValue' => $previousValue,
            'newValue'      => $normalised['logged'],
            'item'          => $this->shapeItem($fresh, true),
        ]);
    }

    /**
     * Validate and normalise a correction value for a given axis.
     * Returns ['stored' => value-to-write-to-DB, 'logged' => value-for-audit-log].
     * Throws InvalidArgumentException on validation failure.
     */
    private function normaliseCorrectionValue(string $axis, $rawValue): array
    {
        switch ($axis) {
            case 'topics':
                if (!is_array($rawValue)) {
                    throw new \InvalidArgumentException('topics must be an array of strings.');
                }
                $clean = array_values(array_unique(array_filter(array_map(
                    fn($t) => trim((string)$t),
                    $rawValue
                ))));
                $clean = array_slice($clean, 0, 5);  // hard cap at 5
                return ['stored' => $clean, 'logged' => $clean];

            case 'sub_brand':
            case 'territory':
            case 'language':
                if ($rawValue === null) {
                    return ['stored' => null, 'logged' => null];
                }
                if (!is_string($rawValue)) {
                    throw new \InvalidArgumentException("$axis must be a string or null.");
                }
                $s = trim($rawValue);
                $v = $s === '' ? null : $s;
                return ['stored' => $v, 'logged' => $v];

            case 'content_type':
                if (!is_string($rawValue)) {
                    throw new \InvalidArgumentException('content_type must be a string.');
                }
                $allowed = ['product', 'article', 'case_study', 'legal', 'landing',
                            'documentation', 'pricing', 'homepage', 'about', 'contact', 'other'];
                $v = strtolower(trim($rawValue));
                if (!in_array($v, $allowed, true)) {
                    throw new \InvalidArgumentException(
                        'content_type must be one of: ' . implode(', ', $allowed)
                    );
                }
                return ['stored' => $v, 'logged' => $v];

            case 'content_date':
                if ($rawValue === null || $rawValue === '') {
                    return ['stored' => null, 'logged' => null];
                }
                if (!is_string($rawValue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawValue)) {
                    throw new \InvalidArgumentException('content_date must be YYYY-MM-DD or null.');
                }
                return ['stored' => $rawValue, 'logged' => $rawValue];

            default:
                // Should be unreachable — caller validates axis against CORRECTABLE_AXES first.
                throw new \InvalidArgumentException("Unsupported axis: $axis");
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

        $inner = trim((string)$value, '{}');
        if ($inner === '') return [];

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

    /** Format an array of strings as a Postgres TEXT[] literal: '{"a","b","c"}'. */
    private function toPgTextArray(array $items): string
    {
        if (empty($items)) return '{}';
        $escaped = array_map(function ($item) {
            $s = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$item);
            return '"' . $s . '"';
        }, $items);
        return '{' . implode(',', $escaped) . '}';
    }

    private function parseJsonField($value): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
