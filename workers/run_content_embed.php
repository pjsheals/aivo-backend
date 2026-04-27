<?php

/**
 * run_content_embed.php — ORBIT Phase 1, Step 3 worker
 *
 * Background worker for embedding a brand's indexed content.
 * Mirrors workers/run_content_crawl.php structure.
 *
 * Manual invocation:
 *   php /app/workers/run_content_embed.php {brandId} [{sourceId}]
 *
 * brandId   — required, embed all unembedded items for this brand
 * sourceId  — optional, restrict to one source within the brand
 *
 * No state machine on a parent row here — the embedder is brand-scoped, not
 * source-scoped. Progress is logged and final stats written to error_log.
 * Per-item state lives on meridian_brand_content_items.embedding (NULL → set).
 */

declare(strict_types=1);

// ── Guard: CLI only ───────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$brandId  = isset($argv[1]) ? (int)$argv[1] : 0;
$sourceId = isset($argv[2]) ? (int)$argv[2] : null;

if ($brandId <= 0) {
    error_log('[ContentEmbedWorker] No brand ID supplied — exiting');
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '512M');

error_log("[ContentEmbedWorker] Starting — brandId={$brandId} sourceId=" . ($sourceId ?? 'all'));

// ── Bootstrap ─────────────────────────────────────────────────────
define('BASE_PATH', realpath(__DIR__ . '/..'));

if (!BASE_PATH || !file_exists(BASE_PATH . '/vendor/autoload.php')) {
    error_log('[ContentEmbedWorker] Cannot resolve app root or vendor/autoload.php');
    exit(1);
}

require BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
try { $dotenv->load(); } catch (\Throwable $e) {}

require BASE_PATH . '/src/bootstrap.php';

use Aivo\Meridian\MeridianContentEmbedder;

$startedAt = microtime(true);

try {
    $embedder = new MeridianContentEmbedder($brandId, $sourceId);
    $stats    = $embedder->run();

    $elapsedSec = round(microtime(true) - $startedAt, 1);

    error_log(sprintf(
        "[ContentEmbedWorker] Completed brandId=%d sourceId=%s in %ss. embedded=%d skipped=%d failed=%d api_calls=%d chars_sent=%d",
        $brandId,
        $sourceId ?? 'all',
        $elapsedSec,
        $stats['embedded'],
        $stats['skipped'],
        $stats['failed'],
        $stats['api_calls'],
        $stats['chars_sent']
    ));

    exit(0);

} catch (\Throwable $e) {
    error_log("[ContentEmbedWorker] FAILED brandId={$brandId} error=" . $e->getMessage());
    error_log($e->getTraceAsString());
    exit(1);
}
