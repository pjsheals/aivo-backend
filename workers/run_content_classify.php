<?php

/**
 * run_content_classify.php — ORBIT Phase 1, Step 4 worker
 *
 * Background worker for classifying a brand's indexed content via Claude Haiku.
 * Mirrors workers/run_content_embed.php structure.
 *
 * Manual invocation:
 *   php /app/workers/run_content_classify.php {brandId} [{sourceId}]
 *
 * brandId   — required, classify all unclassified items for this brand
 * sourceId  — optional, restrict to one source within the brand
 *
 * Per-item state lives on meridian_brand_content_items.classified_at (NULL → set).
 * Re-classify by setting classified_at = NULL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$brandId  = isset($argv[1]) ? (int)$argv[1] : 0;
$sourceId = isset($argv[2]) ? (int)$argv[2] : null;

if ($brandId <= 0) {
    error_log('[ContentClassifyWorker] No brand ID supplied — exiting');
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '512M');

error_log("[ContentClassifyWorker] Starting — brandId={$brandId} sourceId=" . ($sourceId ?? 'all'));

define('BASE_PATH', realpath(__DIR__ . '/..'));

if (!BASE_PATH || !file_exists(BASE_PATH . '/vendor/autoload.php')) {
    error_log('[ContentClassifyWorker] Cannot resolve app root or vendor/autoload.php');
    exit(1);
}

require BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
try { $dotenv->load(); } catch (\Throwable $e) {}

require BASE_PATH . '/src/bootstrap.php';

use Aivo\Meridian\MeridianContentClassifier;

$startedAt = microtime(true);

try {
    $classifier = new MeridianContentClassifier($brandId, $sourceId);
    $stats      = $classifier->run();

    $elapsedSec = round(microtime(true) - $startedAt, 1);

    error_log(sprintf(
        "[ContentClassifyWorker] Completed brandId=%d sourceId=%s in %ss. classified=%d skipped=%d failed=%d api_calls=%d domain_overrides=%d",
        $brandId,
        $sourceId ?? 'all',
        $elapsedSec,
        $stats['classified'],
        $stats['skipped'],
        $stats['failed'],
        $stats['api_calls'],
        $stats['domain_overrides']
    ));

    exit(0);

} catch (\Throwable $e) {
    error_log("[ContentClassifyWorker] FAILED brandId={$brandId} error=" . $e->getMessage());
    error_log($e->getTraceAsString());
    exit(1);
}
