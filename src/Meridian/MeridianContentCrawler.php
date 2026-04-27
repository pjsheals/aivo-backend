<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use DOMDocument;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianContentCrawler — ORBIT Phase 1, Step 2 (Crawler)
 *
 * Fetches and indexes brand content from a public source. Writes to
 * meridian_brand_content_items. Does NOT embed or classify — those are
 * Steps 3 (MeridianContentEmbedder) and 4 (MeridianContentClassifier).
 *
 * Phase 1 supports the 'sitemap' source_type only. content_hub, rss,
 * knowledge_base, and document_repo throw "not yet supported" by design
 * (Decision: ship narrow before broadening).
 *
 * Invoked by workers/run_content_crawl.php (CLI worker).
 *
 * Politeness: 500ms between same-host requests. UA identifies AIVO Meridian.
 * No authenticated crawling. No robots.txt enforcement (Phase 1 = brand-owned
 * content with the brand's permission via onboarding — Decision 6).
 */
class MeridianContentCrawler
{
    private const USER_AGENT          = 'AIVO-Meridian-ORBIT/1.0 (+https://aivoedge.net)';
    private const REQUEST_TIMEOUT     = 30;
    private const POLITENESS_DELAY_MS = 500;
    private const MAX_URLS_PER_CRAWL  = 5000;       // safety cap
    private const MAX_CONTENT_BYTES   = 5_000_000;  // 5MB per page max
    private const MAX_SITEMAP_DEPTH   = 2;          // sitemap-of-sitemaps recursion limit

    // Tracking-related query params to strip during canonicalisation
    private const TRACKING_PARAMS = [
        'utm_source','utm_medium','utm_campaign','utm_term','utm_content',
        'fbclid','gclid','msclkid','mc_cid','mc_eid','ref','ref_src',
        '_ga','_gl','yclid','_hsenc','_hsmi',
    ];

    private int $sourceId;
    private ?object $source = null;
    private ?object $brand  = null;

    private int   $itemsIndexed = 0;  // newly inserted or content changed
    private int   $itemsSkipped = 0;  // hash unchanged
    private int   $itemsFailed  = 0;
    private float $lastRequestAt = 0.0;

    public function __construct(int $sourceId)
    {
        $this->sourceId = $sourceId;
        $this->loadSource();
    }

    /**
     * Run the full crawl. Returns summary statistics.
     *
     * @return array{indexed:int, skipped:int, failed:int}
     * @throws \RuntimeException on hard failures (source-level errors)
     */
    public function run(): array
    {
        $this->logInfo("Crawl starting", [
            'source_id'   => $this->sourceId,
            'source_type' => $this->source->source_type,
            'source_url'  => $this->source->source_url,
            'brand_id'    => $this->brand->id,
            'brand_name'  => $this->brand->name,
        ]);

        switch ($this->source->source_type) {
            case 'sitemap':
                $this->crawlSitemap();
                break;
            case 'content_hub':
            case 'rss':
            case 'knowledge_base':
            case 'document_repo':
                throw new \RuntimeException(
                    "source_type '{$this->source->source_type}' is not yet supported in Phase 1. "
                    . "Currently only 'sitemap' is implemented."
                );
            default:
                throw new \RuntimeException("Unknown source_type: {$this->source->source_type}");
        }

        $summary = [
            'indexed' => $this->itemsIndexed,
            'skipped' => $this->itemsSkipped,
            'failed'  => $this->itemsFailed,
        ];

        $this->logInfo("Crawl complete", $summary);
        return $summary;
    }

    public function getStats(): array
    {
        return [
            'indexed' => $this->itemsIndexed,
            'skipped' => $this->itemsSkipped,
            'failed'  => $this->itemsFailed,
        ];
    }

    // -------------------------------------------------------------------------
    // Bootstrapping
    // -------------------------------------------------------------------------

    private function loadSource(): void
    {
        $source = DB::table('meridian_brand_content_sources')
            ->where('id', $this->sourceId)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            throw new \RuntimeException("Source {$this->sourceId} not found or soft-deleted.");
        }

        $brand = DB::table('meridian_brands')->find($source->brand_id);
        if (!$brand) {
            throw new \RuntimeException(
                "Brand {$source->brand_id} for source {$this->sourceId} not found."
            );
        }

        $this->source = $source;
        $this->brand  = $brand;
    }

    // -------------------------------------------------------------------------
    // Sitemap crawler
    // -------------------------------------------------------------------------

    private function crawlSitemap(): void
    {
        $urls = $this->fetchSitemapUrls($this->source->source_url, 0);

        if (empty($urls)) {
            throw new \RuntimeException(
                "Sitemap returned 0 URLs from {$this->source->source_url}"
            );
        }

        // Deduplicate + cap
        $urls = array_values(array_unique($urls));
        if (count($urls) > self::MAX_URLS_PER_CRAWL) {
            $this->logInfo("Capping URL list", [
                'discovered' => count($urls),
                'capped_at'  => self::MAX_URLS_PER_CRAWL,
            ]);
            $urls = array_slice($urls, 0, self::MAX_URLS_PER_CRAWL);
        }

        $this->logInfo("Sitemap parsed", ['urls_to_index' => count($urls)]);

        foreach ($urls as $i => $url) {
            try {
                $this->indexUrl($url);
            } catch (\Throwable $e) {
                $this->itemsFailed++;
                $this->logError("Index failed", [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
            }

            // Periodic progress write — every 25 URLs
            if (($i + 1) % 25 === 0) {
                DB::table('meridian_brand_content_sources')
                    ->where('id', $this->sourceId)
                    ->update([
                        'items_indexed' => $this->itemsIndexed + $this->itemsSkipped,
                        'updated_at'    => now(),
                    ]);

                $this->logInfo("Progress", [
                    'processed' => $i + 1,
                    'total'     => count($urls),
                    'indexed'   => $this->itemsIndexed,
                    'skipped'   => $this->itemsSkipped,
                    'failed'    => $this->itemsFailed,
                ]);
            }
        }
    }

    /**
     * Fetch a sitemap URL and return all <loc> URLs found.
     * Recurses up to MAX_SITEMAP_DEPTH if it's a sitemap index file.
     *
     * @return string[]
     */
    private function fetchSitemapUrls(string $sitemapUrl, int $depth): array
    {
        if ($depth > self::MAX_SITEMAP_DEPTH) {
            $this->logError("Sitemap depth exceeded", [
                'url'   => $sitemapUrl,
                'depth' => $depth,
            ]);
            return [];
        }

        $response = $this->fetchUrl($sitemapUrl, 'application/xml,text/xml,*/*');
        if (!$response || $response['body'] === null) {
            $code = $response['http_code'] ?? 0;
            throw new \RuntimeException(
                "Failed to fetch sitemap: {$sitemapUrl} (HTTP {$code})"
            );
        }

        $body = $response['body'];

        // Decompress if response was a raw .gz file (curl auto-decodes Content-Encoding,
        // but .xml.gz endpoints sometimes serve gzipped bytes without that header)
        if (strncmp($body, "\x1f\x8b", 2) === 0) {
            $decoded = @gzdecode($body);
            if ($decoded === false) {
                throw new \RuntimeException("Failed to decompress gzipped sitemap: {$sitemapUrl}");
            }
            $body = $decoded;
        }

        $prevErr = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);

        if ($xml === false) {
            throw new \RuntimeException("Sitemap XML parse failed: {$sitemapUrl}");
        }

        $urls = [];
        $rootName = strtolower($xml->getName());

        if ($rootName === 'sitemapindex') {
            // Sitemap of sitemaps — recurse one level
            foreach ($xml->sitemap as $sm) {
                $childUrl = trim((string)$sm->loc);
                if ($childUrl === '') continue;
                try {
                    $childUrls = $this->fetchSitemapUrls($childUrl, $depth + 1);
                    $urls = array_merge($urls, $childUrls);
                } catch (\Throwable $e) {
                    // Don't let one bad child sitemap kill the whole crawl
                    $this->logError("Child sitemap failed", [
                        'url'   => $childUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($rootName === 'urlset') {
            foreach ($xml->url as $u) {
                $loc = trim((string)$u->loc);
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        } else {
            $this->logError("Unknown sitemap root element", [
                'root' => $rootName,
                'url'  => $sitemapUrl,
            ]);
        }

        return $urls;
    }

    // -------------------------------------------------------------------------
    // URL indexing (fetch + extract + upsert)
    // -------------------------------------------------------------------------

    private function indexUrl(string $url): void
    {
        $canonical = $this->canonicalizeUrl($url);

        $existing = DB::table('meridian_brand_content_items')
            ->where('brand_id', $this->brand->id)
            ->where('url_canonical', $canonical)
            ->first();

        $response = $this->fetchUrl($url, 'text/html,application/xhtml+xml,*/*');
        if (!$response || $response['body'] === null || $response['http_code'] >= 400) {
            $this->itemsFailed++;
            $code = $response['http_code'] ?? 0;
            $this->logError("Fetch failed", ['url' => $url, 'http_code' => $code]);
            return;
        }

        $html = $response['body'];
        $hash = hash('sha256', $html);

        // Hash unchanged → just bump last_indexed_at and skip extraction/embedding work
        if ($existing && $existing->content_html_hash === $hash) {
            DB::table('meridian_brand_content_items')
                ->where('id', $existing->id)
                ->update(['last_indexed_at' => now()]);
            $this->itemsSkipped++;
            return;
        }

        $extracted = $this->extractContent($html);

        $payload = [
            'brand_id'          => $this->brand->id,
            'source_id'         => $this->sourceId,
            'url'               => $url,
            'url_canonical'     => $canonical,
            'title'             => $extracted['title'],
            'content_text'      => $extracted['content_text'],
            'content_html_hash' => $hash,
            'content_date'      => $extracted['content_date'],
            'last_indexed_at'   => now(),
        ];

        if ($existing) {
            // Re-index: clear stale embedding so Step 3 (Embedder) re-embeds.
            // Keep existing classifications — user may have corrected them.
            $payload['embedding']            = null;
            $payload['embedding_input_text'] = null;

            DB::table('meridian_brand_content_items')
                ->where('id', $existing->id)
                ->update($payload);
        } else {
            $payload['first_indexed_at'] = now();
            DB::table('meridian_brand_content_items')->insert($payload);
        }

        $this->itemsIndexed++;
    }

    // -------------------------------------------------------------------------
    // HTTP fetch with politeness
    // -------------------------------------------------------------------------

    /**
     * @return array{body:?string, http_code:int, final_url:string}|null
     */
    private function fetchUrl(string $url, string $accept = '*/*'): ?array
    {
        $this->politenessDelay();

        $ch = curl_init($url);
        if ($ch === false) return null;

        $bodyBuffer = '';
        $tooBig     = false;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                'Accept: ' . $accept,
                'Accept-Language: en;q=0.9,*;q=0.5',
            ],
            // Empty string → curl accepts any encoding it supports and auto-decodes
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Stream into buffer with a hard size cap
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$bodyBuffer, &$tooBig) {
                if ($tooBig) return 0;
                $bodyBuffer .= $chunk;
                if (strlen($bodyBuffer) > self::MAX_CONTENT_BYTES) {
                    $tooBig = true;
                    return 0; // abort transfer
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errno    = curl_errno($ch);
        curl_close($ch);

        $this->lastRequestAt = microtime(true);

        if ($tooBig) {
            $this->logError("Response exceeded max size", ['url' => $url]);
            return ['body' => null, 'http_code' => 0, 'final_url' => $finalUrl];
        }

        // Non-zero curl error AND empty body = real failure
        if ($errno !== 0 && $bodyBuffer === '') {
            return ['body' => null, 'http_code' => 0, 'final_url' => $finalUrl];
        }

        return [
            'body'      => $bodyBuffer,
            'http_code' => $httpCode,
            'final_url' => $finalUrl,
        ];
    }

    private function politenessDelay(): void
    {
        if ($this->lastRequestAt === 0.0) return;

        $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
        $delayMs   = self::POLITENESS_DELAY_MS - $elapsedMs;
        if ($delayMs > 0) {
            usleep((int)($delayMs * 1000));
        }
    }

    // -------------------------------------------------------------------------
    // HTML content extraction (readability-style heuristic)
    // -------------------------------------------------------------------------

    /**
     * @return array{title:?string, content_text:?string, content_date:?string}
     */
    private function extractContent(string $html): array
    {
        $doc = new DOMDocument();
        $prevErr = libxml_use_internal_errors(true);
        $loaded  = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);

        if (!$loaded) {
            return ['title' => null, 'content_text' => null, 'content_date' => null];
        }

        // ── Title ──
        $title = null;
        $titleNodes = $doc->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }
        if (!$title) {
            $h1Nodes = $doc->getElementsByTagName('h1');
            if ($h1Nodes->length > 0) {
                $title = trim($h1Nodes->item(0)->textContent);
            }
        }
        $title = $title ? mb_substr($title, 0, 500) : null;

        // ── Date (extracted before stripping noise — meta tags live in <head>) ──
        $contentDate = $this->extractDate($doc);

        // ── Strip noise elements ──
        foreach (['script','style','noscript','nav','header','footer','aside','form','iframe','svg'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            // Iterate backwards — removing during forward iteration mutates the live NodeList
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $n = $nodes->item($i);
                if ($n && $n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }

        // ── Pick best content root: <main> > <article> > <body> ──
        $root = null;
        foreach (['main','article'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            if ($nodes->length > 0) {
                $root = $nodes->item(0);
                break;
            }
        }
        if (!$root) {
            $bodyNodes = $doc->getElementsByTagName('body');
            $root = $bodyNodes->length > 0 ? $bodyNodes->item(0) : null;
        }

        $contentText = null;
        if ($root) {
            $text = $root->textContent;
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = trim($text ?? '');
            $contentText = $text !== '' ? $text : null;
        }

        return [
            'title'        => $title,
            'content_text' => $contentText,
            'content_date' => $contentDate,
        ];
    }

    /**
     * Best-effort publication date from common meta tags / <time> elements.
     * Returns 'YYYY-MM-DD' or null.
     */
    private function extractDate(DOMDocument $doc): ?string
    {
        $candidates = [];

        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $key = strtolower(
                $meta->getAttribute('property') ?: $meta->getAttribute('name') ?: ''
            );
            $val = trim($meta->getAttribute('content') ?: '');
            if ($val === '') continue;

            if (in_array($key, [
                'article:published_time', 'og:article:published_time',
                'date', 'pubdate', 'datepublished', 'article:published',
                'sailthru.date', 'parsely-pub-date',
            ], true)) {
                $candidates[] = $val;
            }
        }

        foreach ($doc->getElementsByTagName('time') as $time) {
            $dt = trim($time->getAttribute('datetime') ?: '');
            if ($dt !== '') {
                $candidates[] = $dt;
            }
        }

        foreach ($candidates as $c) {
            $ts = strtotime($c);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // URL canonicalisation
    // -------------------------------------------------------------------------

    /**
     * Normalise a URL so different surface forms of the same resource collapse
     * to one key:
     *  - lowercase scheme + host
     *  - strip default port
     *  - strip fragment
     *  - strip tracking query params (utm_*, fbclid, gclid, etc.)
     *  - sort remaining query params alphabetically
     *  - strip trailing slash (except for root "/")
     *  - collapse repeated slashes in path
     */
    private function canonicalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || empty($parts['host'])) {
            // Unparseable — best-effort lowercase
            return strtolower(trim($url));
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host']);
        $port   = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach (self::TRACKING_PARAMS as $bad) {
                unset($params[$bad]);
            }
            ksort($params);
            $query = http_build_query($params);
        }

        $authority = $host . ($port ? ":{$port}" : '');
        $canonical = $scheme . '://' . $authority . $path;
        if ($query !== '') $canonical .= '?' . $query;

        return $canonical;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function logInfo(string $msg, array $context = []): void
    {
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[ContentCrawler] {$msg}{$ctx}");
    }

    private function logError(string $msg, array $context = []): void
    {
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[ContentCrawler] ERROR: {$msg}{$ctx}");
    }
}
