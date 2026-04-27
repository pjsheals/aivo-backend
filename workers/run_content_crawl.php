<?php

/**
 * run_content_crawl.php — ORBIT Phase 1, Step 2 worker
 *
 * Background worker for crawling a single brand content source.
 * Mirrors workers/run_audit.php structure (CLI guard, bootstrap, status state machine).
 *
 * Manual invocation (testing):
 *   php /app/workers/run_content_crawl.php {sourceId}
 *
 * Will be invoked from MeridianContentIndexerController in Step 5 via:
 *   exec('php ' . BASE_PATH . '/workers/run_content_crawl.php ' . $sourceId . ' > /dev/null 2>&1 &');
 *
 * Updates meridian_brand_content_sources.status throughout:
 *   pending|paused|completed|failed → crawling → completed|failed
 */

declare(strict_types=1);

// ── Guard: CLI only ───────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$sourceId = isset($argv[1]) ? (int)$argv[1] : null;
if (!$sourceId) {
    error_log('[ContentCrawlWorker] No source ID supplied — exiting');
    exit(1);
}

// Crawls of large sitemaps run for many minutes.
set_time_limit(0);
ini_set('memory_limit', '512M');

error_log("[ContentCrawlWorker] Starting — sourceId={$sourceId}");

// ── Bootstrap ─────────────────────────────────────────────────────
define('BASE_PATH', realpath(__DIR__ . '/..'));

if (!BASE_PATH || !file_exists(BASE_PATH . '/vendor/autoload.php')) {
    error_log('[ContentCrawlWorker] Cannot resolve app root or vendor/autoload.php');
    exit(1);
}

require BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
try { $dotenv->load(); } catch (\Throwable $e) {}

require BASE_PATH . '/src/bootstrap.php';

use Aivo\Meridian\MeridianContentCrawler;
use Illuminate\Database\Capsule\Manager as DB;

// ── Load source ───────────────────────────────────────────────────
$source = DB::table('meridian_brand_content_sources')
    ->where('id', $sourceId)
    ->whereNull('deleted_at')
    ->first();

if (!$source) {
    error_log("[ContentCrawlWorker] Source {$sourceId} not found or soft-deleted");
    exit(1);
}

if ($source->status === 'crawling') {
    error_log("[ContentCrawlWorker] Source {$sourceId} already crawling — exiting (refusing to double-run)");
    exit(0);
}

if ($source->status === 'paused' || !$source->is_active) {
    error_log("[ContentCrawlWorker] Source {$sourceId} is paused or inactive — exiting");
    exit(0);
}

error_log("[ContentCrawlWorker] Source loaded — type={$source->source_type} url={$source->source_url}");

// ── Mark as crawling ──────────────────────────────────────────────
DB::table('meridian_brand_content_sources')
    ->where('id', $sourceId)
    ->update([
        'status'     => 'crawling',
        'last_error' => null,
        'updated_at' => now(),
    ]);

// ── Run crawler ───────────────────────────────────────────────────
$startedAt = microtime(true);

try {
    $crawler = new MeridianContentCrawler($sourceId);
    $stats   = $crawler->run();

    $elapsedSec   = round(microtime(true) - $startedAt, 1);
    $totalIndexed = (int)($stats['indexed'] ?? 0) + (int)($stats['skipped'] ?? 0);

    // Compute next_crawl_at from cadence (0 days = manual only → null)
    $cadenceDays = (int)($source->crawl_cadence_days ?: 30);
    $nextCrawl   = $cadenceDays > 0
        ? date('Y-m-d H:i:s', time() + ($cadenceDays * 86400))
        : null;

    DB::table('meridian_brand_content_sources')
        ->where('id', $sourceId)
        ->update([
            'status'          => 'completed',
            'last_crawled_at' => now(),
            'next_crawl_at'   => $nextCrawl,
            'items_indexed'   => $totalIndexed,
            'last_error'      => null,
            'updated_at'      => now(),
        ]);

    error_log(sprintf(
        "[ContentCrawlWorker] Completed sourceId=%d in %ss. indexed=%d skipped=%d failed=%d total_in_corpus=%d",
        $sourceId,
        $elapsedSec,
        $stats['indexed'],
        $stats['skipped'],
        $stats['failed'],
        $totalIndexed
    ));

    exit(0);

} catch (\Throwable $e) {
    $msg = $e->getMessage();
    error_log("[ContentCrawlWorker] FAILED sourceId={$sourceId} error={$msg}");
    error_log($e->getTraceAsString());

    DB::table('meridian_brand_content_sources')
        ->where('id', $sourceId)
        ->update([
            'status'     => 'failed',
            'last_error' => mb_substr($msg, 0, 1000),
            'updated_at' => now(),
        ]);

    exit(1);
}
