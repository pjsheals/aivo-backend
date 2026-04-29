<?php

declare(strict_types=1);

namespace Aivo\Orbit\Services;

use Aivo\Orbit\Exceptions\SearchProviderException;

/**
 * EmbeddingService — wraps OpenAI's text-embedding-3-small.
 *
 * Used by ORBIT to vectorise:
 *   1. The gap's claim text (embedded once per search run)
 *   2. Each candidate's title + snippet (embedded once per candidate)
 *
 * Cosine similarity between claim and candidate vectors becomes the
 * relevance_multiplier in the scoring formula.
 *
 * Cost: text-embedding-3-small is $0.02 per 1M tokens.
 *       Typical ORBIT search: 1 claim (~30 tokens) + 50 candidates (~100 tokens each)
 *       = ~5,030 tokens = $0.0001 per search. Trivial.
 */
final class EmbeddingService
{
    private const ENDPOINT        = 'https://api.openai.com/v1/embeddings';
    private const MODEL           = 'text-embedding-3-small';
    public const  DIMENSIONS      = 1536;
    private const TIMEOUT_SECONDS = 20;
    private const USER_AGENT      = 'AIVO-ORBIT/1.0';

    public function __construct(
        private readonly string $apiKey
    ) {
        if (trim($this->apiKey) === '') {
            throw new \InvalidArgumentException(
                'EmbeddingService requires a non-empty OPENAI_API_KEY.'
            );
        }
    }

    /**
     * Embed a single text string.
     *
     * @return float[] 1536-dim vector
     * @throws SearchProviderException
     */
    public function embed(string $text): array
    {
        $vectors = $this->embedBatch([$text]);
        return $vectors[0] ?? [];
    }

    /**
     * Embed multiple text strings in a single API call. Most efficient for
     * embedding a batch of candidates at once.
     *
     * @param string[] $texts Each string up to ~8000 tokens.
     * @return float[][] Array of 1536-dim vectors, in the same order as input.
     * @throws SearchProviderException
     */
    public function embedBatch(array $texts): array
    {
        // Filter empty strings and remember their positions so we can return
        // empty vectors for them in the right slots
        $cleaned   = [];
        $positions = [];
        foreach ($texts as $idx => $t) {
            $t = is_string($t) ? trim($t) : '';
            if ($t === '') {
                continue;
            }
            // Truncate aggressively — 8000 tokens is roughly 32,000 chars.
            // We keep the first 4000 chars per text which gives plenty of signal
            // for relevance without burning tokens.
            if (mb_strlen($t) > 4000) {
                $t = mb_substr($t, 0, 4000);
            }
            $cleaned[]                 = $t;
            $positions[count($cleaned) - 1] = $idx;
        }

        if ($cleaned === []) {
            // Return empty vectors for every input
            return array_fill(0, count($texts), []);
        }

        $payload = json_encode([
            'model' => self::MODEL,
            'input' => $cleaned,
        ]);
        if ($payload === false) {
            throw new SearchProviderException('Failed to encode embedding payload.');
        }

        [$body, $httpCode, $err] = $this->httpPost(self::ENDPOINT, $payload);
        if ($body === null) {
            throw new SearchProviderException("OpenAI embedding request failed: {$err}");
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new SearchProviderException(
                "OpenAI auth failed (HTTP {$httpCode}). Check OPENAI_API_KEY in Railway."
            );
        }
        if ($httpCode === 429) {
            throw new SearchProviderException('OpenAI rate limit hit (HTTP 429).');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SearchProviderException(
                "OpenAI embeddings returned HTTP {$httpCode}: " . substr($body, 0, 400)
            );
        }

        $data = json_decode($body, true);
        $items = $data['data'] ?? null;
        if (!is_array($items)) {
            throw new SearchProviderException('OpenAI embedding response missing data array.');
        }

        // Build output in the same order as the input array; empty inputs get []
        $out = array_fill(0, count($texts), []);
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $i      = (int) ($row['index'] ?? -1);
            $vector = $row['embedding'] ?? null;
            if ($i < 0 || !is_array($vector)) {
                continue;
            }
            $originalIndex = $positions[$i] ?? null;
            if ($originalIndex !== null) {
                $out[$originalIndex] = array_map('floatval', $vector);
            }
        }

        return $out;
    }

    /**
     * Cosine similarity between two equal-length vectors. Returns a value in
     * [-1, 1]; for OpenAI embeddings this is almost always in [0, 1].
     *
     * Returns 0.0 if either vector is empty or zero-length.
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $na  += $av * $av;
            $nb  += $bv * $bv;
        }

        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * Format a PHP float[] vector as a Postgres-pgvector literal:
     *   '[0.123,0.456,...]'
     *
     * Returns null if the vector is empty.
     */
    public static function toPgVector(array $vector): ?string
    {
        if ($vector === []) {
            return null;
        }
        $parts = [];
        foreach ($vector as $v) {
            $parts[] = number_format((float) $v, 8, '.', '');
        }
        return '[' . implode(',', $parts) . ']';
    }

    /**
     * @return array{0: ?string, 1: int, 2: string}
     */
    private function httpPost(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $resp  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        if ($resp === false || $resp === null) {
            return [null, $code, $error !== '' ? $error : 'cURL error'];
        }
        return [(string) $resp, $code, ''];
    }
}
