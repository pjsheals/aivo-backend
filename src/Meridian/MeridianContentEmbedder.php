<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianContentEmbedder — ORBIT Phase 1, Step 3 (Embedder)
 *
 * Reads items from meridian_brand_content_items where:
 *   - content_text IS NOT NULL
 *   - embedding IS NULL  (i.e. not yet embedded, or invalidated by re-crawl)
 *
 * Sends them in batches to OpenAI's text-embedding-3-small model and writes:
 *   - embedding              vector(1536)
 *   - embedding_input_text   the exact text that was sent (for audit + reproducibility)
 *
 * Cost: ~$0.02 per 1M tokens. A 5,000-char page ≈ 1,250 tokens. 1,500-page L'Oréal
 * site ≈ ~1.9M tokens ≈ $0.04 per full re-embed. Negligible.
 *
 * Idempotency: re-running this on a brand picks up exactly the rows that need
 * embedding. Already-embedded rows are skipped. Re-crawls clear `embedding` so
 * changed pages are naturally re-embedded next run.
 *
 * Invoked by workers/run_content_embed.php (CLI worker) OR synchronously via
 * MeridianContentIndexerController::debugEmbedSync() for diagnostics.
 */
class MeridianContentEmbedder
{
    // OpenAI endpoint + model
    private const OPENAI_URL    = 'https://api.openai.com/v1/embeddings';
    private const MODEL         = 'text-embedding-3-small';
    private const DIMENSIONS    = 1536;

    // Batching + safety
    private const BATCH_SIZE         = 100;     // OpenAI accepts up to 2048; 100 keeps requests reasonable
    private const MAX_INPUT_CHARS    = 30_000;  // text-embedding-3-small handles ~32k chars (~8k tokens)
    private const REQUEST_TIMEOUT    = 120;     // seconds
    private const MAX_RETRIES        = 3;
    private const RETRY_BACKOFF_BASE = 2;       // seconds, exponential

    private int $brandId;
    private ?int $sourceId; // optional filter — embed only items from one source

    private int $itemsEmbedded   = 0;
    private int $itemsSkipped    = 0;  // had no content_text
    private int $itemsFailed     = 0;
    private int $apiCalls        = 0;
    private int $totalCharsSent  = 0;
    private array $errors        = [];

    public function __construct(int $brandId, ?int $sourceId = null)
    {
        $this->brandId  = $brandId;
        $this->sourceId = $sourceId;
    }

    /**
     * @return array{embedded:int, skipped:int, failed:int, api_calls:int, chars_sent:int, errors:array}
     */
    public function run(): array
    {
        $apiKey = $this->getApiKey();

        $this->logInfo("Embedder starting", [
            'brand_id'  => $this->brandId,
            'source_id' => $this->sourceId,
        ]);

        // Fetch all candidates in one query — embeddings are 1536 floats, but the
        // candidate list is just IDs + text, so a brand of 1,500 items is fine.
        $query = DB::table('meridian_brand_content_items')
            ->where('brand_id', $this->brandId)
            ->whereNull('embedding')
            ->whereNotNull('content_text')
            ->select(['id', 'title', 'content_text']);

        if ($this->sourceId !== null) {
            $query->where('source_id', $this->sourceId);
        }

        $candidates = $query->orderBy('id', 'asc')->get();
        $totalCandidates = $candidates->count();

        if ($totalCandidates === 0) {
            $this->logInfo("Nothing to embed", ['brand_id' => $this->brandId]);
            return $this->summary();
        }

        $this->logInfo("Embedding candidates", ['count' => $totalCandidates]);

        // Batch and process
        $batches = array_chunk($candidates->all(), self::BATCH_SIZE);
        foreach ($batches as $batchIndex => $batch) {
            try {
                $this->processBatch($batch, $apiKey);
                $this->logInfo("Batch complete", [
                    'batch'           => $batchIndex + 1,
                    'of'              => count($batches),
                    'embedded_so_far' => $this->itemsEmbedded,
                ]);
            } catch (\Throwable $e) {
                // Whole batch failed — count all of its items as failed and continue.
                $this->itemsFailed += count($batch);
                $this->errors[] = [
                    'batch' => $batchIndex + 1,
                    'error' => $e->getMessage(),
                ];
                $this->logError("Batch failed", [
                    'batch' => $batchIndex + 1,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logInfo("Embedder complete", $this->summary());
        return $this->summary();
    }

    // ─────────────────────────────────────────────────────────────
    // Per-batch processing
    // ─────────────────────────────────────────────────────────────

    private function processBatch(array $batch, string $apiKey): void
    {
        $inputs   = [];   // texts to send, indexed by item id
        $itemMap  = [];   // batch index → item id

        foreach ($batch as $item) {
            $inputText = $this->buildInputText($item);
            if ($inputText === null || $inputText === '') {
                $this->itemsSkipped++;
                continue;
            }

            $idx           = count($inputs);
            $inputs[$idx]  = $inputText;
            $itemMap[$idx] = (int)$item->id;
            $this->totalCharsSent += strlen($inputText);
        }

        if (empty($inputs)) {
            return; // nothing to embed in this batch
        }

        $vectors = $this->callOpenAi(array_values($inputs), $apiKey);

        // Persist results
        foreach ($vectors as $idx => $vector) {
            $itemId = $itemMap[$idx] ?? null;
            if ($itemId === null) continue;

            $vectorString = $this->vectorToPgString($vector);

            // Explicit ::vector cast — Capsule passes parameters as strings, and
            // while pgvector usually accepts implicit text→vector coercion on
            // column assignment, the explicit cast removes any ambiguity.
            DB::statement(
                'UPDATE meridian_brand_content_items
                    SET embedding            = ?::vector,
                        embedding_input_text = ?,
                        last_indexed_at      = NOW()
                    WHERE id = ?',
                [$vectorString, $inputs[$idx], $itemId]
            );

            $this->itemsEmbedded++;
        }
    }

    /**
     * Construct the text to embed for one item.
     * Title + double-newline + content_text, truncated to MAX_INPUT_CHARS.
     */
    private function buildInputText(object $item): ?string
    {
        $title   = trim((string)($item->title ?? ''));
        $content = trim((string)($item->content_text ?? ''));

        if ($content === '' && $title === '') {
            return null;
        }

        $combined = ($title !== '' ? $title . "\n\n" : '') . $content;

        if (mb_strlen($combined) > self::MAX_INPUT_CHARS) {
            $combined = mb_substr($combined, 0, self::MAX_INPUT_CHARS);
            $this->logInfo("Truncated input", [
                'item_id'      => $item->id,
                'orig_length'  => mb_strlen(($title !== '' ? $title . "\n\n" : '') . $content),
                'final_length' => self::MAX_INPUT_CHARS,
            ]);
        }

        return $combined;
    }

    // ─────────────────────────────────────────────────────────────
    // OpenAI HTTP call with retry
    // ─────────────────────────────────────────────────────────────

    /**
     * Send a batch of inputs to OpenAI and return an array of 1536-dim float arrays,
     * indexed in the same order as inputs.
     *
     * @param string[] $inputs
     * @return array<int, float[]>
     */
    private function callOpenAi(array $inputs, string $apiKey): array
    {
        $payload = json_encode([
            'model' => self::MODEL,
            'input' => $inputs,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode embedding request payload');
        }

        $attempt    = 0;
        $lastError  = '';
        $lastStatus = 0;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $this->apiCalls++;

            $ch = curl_init(self::OPENAI_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response   = curl_exec($ch);
            $httpCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno  = curl_errno($ch);
            $curlError  = curl_error($ch);
            curl_close($ch);

            $lastStatus = $httpCode;

            if ($curlErrno !== 0) {
                $lastError = "curl error {$curlErrno}: {$curlError}";
                $this->logError("OpenAI request failed (curl)", [
                    'attempt' => $attempt,
                    'error'   => $lastError,
                ]);
                $this->backoff($attempt);
                continue;
            }

            if ($httpCode === 200) {
                $decoded = json_decode((string)$response, true);
                if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
                    throw new \RuntimeException(
                        'OpenAI 200 but malformed response: ' . substr((string)$response, 0, 500)
                    );
                }

                // Verify count matches and dimensions are correct
                if (count($decoded['data']) !== count($inputs)) {
                    throw new \RuntimeException(sprintf(
                        'OpenAI returned %d embeddings for %d inputs',
                        count($decoded['data']),
                        count($inputs)
                    ));
                }

                // Sort by index in case OpenAI ever returns out of order (it shouldn't, but safe)
                usort($decoded['data'], fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

                $vectors = [];
                foreach ($decoded['data'] as $idx => $entry) {
                    if (!isset($entry['embedding']) || !is_array($entry['embedding'])) {
                        throw new \RuntimeException("OpenAI response missing embedding at index {$idx}");
                    }
                    if (count($entry['embedding']) !== self::DIMENSIONS) {
                        throw new \RuntimeException(sprintf(
                            'Expected %d dims at index %d, got %d',
                            self::DIMENSIONS,
                            $idx,
                            count($entry['embedding'])
                        ));
                    }
                    $vectors[$idx] = $entry['embedding'];
                }

                return $vectors;
            }

            // Retry on 429 (rate limit) and 5xx (server errors). Don't retry 4xx (client errors).
            if ($httpCode === 429 || $httpCode >= 500) {
                $lastError = "HTTP {$httpCode}: " . substr((string)$response, 0, 500);
                $this->logError("OpenAI request retryable failure", [
                    'attempt'    => $attempt,
                    'http_code'  => $httpCode,
                    'response'   => substr((string)$response, 0, 200),
                ]);
                $this->backoff($attempt);
                continue;
            }

            // Non-retryable client error
            throw new \RuntimeException(
                "OpenAI HTTP {$httpCode}: " . substr((string)$response, 0, 500)
            );
        }

        throw new \RuntimeException(
            "OpenAI request failed after {$attempt} attempts. Last status: {$lastStatus}. Last error: {$lastError}"
        );
    }

    private function backoff(int $attempt): void
    {
        $sleepSec = self::RETRY_BACKOFF_BASE ** $attempt;
        sleep($sleepSec);
    }

    // ─────────────────────────────────────────────────────────────
    // pgvector formatting
    // ─────────────────────────────────────────────────────────────

    /**
     * pgvector accepts vector literals as the string '[v1,v2,...,vN]'.
     * We bind it as a plain string and Postgres casts it on insert.
     */
    private function vectorToPgString(array $vector): string
    {
        // Use sprintf to ensure consistent precision; default float-to-string in PHP can vary.
        $parts = [];
        foreach ($vector as $v) {
            $parts[] = sprintf('%.8f', (float)$v);
        }
        return '[' . implode(',', $parts) . ']';
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function getApiKey(): string
    {
        $key = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
        if (!$key) {
            throw new \RuntimeException('OPENAI_API_KEY env var is not set');
        }
        return $key;
    }

    public function getStats(): array
    {
        return $this->summary();
    }

    private function summary(): array
    {
        return [
            'embedded'   => $this->itemsEmbedded,
            'skipped'    => $this->itemsSkipped,
            'failed'     => $this->itemsFailed,
            'api_calls'  => $this->apiCalls,
            'chars_sent' => $this->totalCharsSent,
            'errors'     => $this->errors,
        ];
    }

    private function logInfo(string $msg, array $context = []): void
    {
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[ContentEmbedder] {$msg}{$ctx}");
    }

    private function logError(string $msg, array $context = []): void
    {
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[ContentEmbedder] ERROR: {$msg}{$ctx}");
    }
}
