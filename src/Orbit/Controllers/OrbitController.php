<?php

declare(strict_types=1);

namespace Aivo\Orbit\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Orbit\Services\CandidateScorer;
use Aivo\Orbit\Services\CitationPlatformResolver;
use Aivo\Orbit\Services\EmbeddingService;
use Aivo\Orbit\Services\OrbitSearchOrchestrator;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OrbitController — production-facing ORBIT endpoints for agency users.
 *
 * Uses MeridianAuth session token (Bearer) — NOT the X-Meridian-Secret header.
 * Enforces tenant isolation: a user can only run searches against gaps that
 * belong to their own agency.
 *
 * Routes:
 *   POST /api/orbit/search       — run a gap search
 *   GET  /api/orbit/runs/{id}    — fetch a previous run + its results
 *   POST /api/orbit/results/{id}/accept  — mark a result as accepted
 *   POST /api/orbit/results/{id}/reject  — mark a result as rejected
 */
class OrbitController
{
    /**
     * POST /api/orbit/search
     *
     * Body:
     * {
     *   "gap_id":              123,
     *   "requested_tiers":     ["T1.*","T2.*"],   // optional
     *   "requested_sentiment": "positive",         // optional
     *   "per_provider_cap":    3                   // optional, 1-10
     * }
     *
     * Returns the run summary with persisted run_id + ranked results.
     * 403 if the gap doesn't belong to the caller's agency.
     */
    public function search(): void
    {
        $auth = MeridianAuth::require('analyst');

        $body  = $this->readJsonBody();
        $gapId = (int) ($body['gap_id'] ?? 0);
        if ($gapId <= 0) {
            http_response_code(400);
            json_response(['error' => 'Missing or invalid gap_id (must be positive integer).']);
            return;
        }

        // Tenant isolation — verify the gap belongs to this agency
        $gap = Capsule::table('meridian_competitive_citation_gaps')
            ->where('id', $gapId)
            ->first();
        if (!$gap) {
            http_response_code(404);
            json_response(['error' => "Gap {$gapId} not found."]);
            return;
        }
        if ((int) ($gap->agency_id ?? 0) !== $auth->agency_id && !$auth->is_superadmin) {
            http_response_code(403);
            json_response(['error' => 'You do not have access to this gap.']);
            return;
        }

        $requestedTiers = [];
        if (!empty($body['requested_tiers']) && is_array($body['requested_tiers'])) {
            $requestedTiers = array_values(array_filter(
                array_map(static fn ($t) => trim((string) $t), $body['requested_tiers']),
                static fn ($t) => $t !== ''
            ));
        }

        $sentiment = (string) ($body['requested_sentiment'] ?? 'positive');
        if (!in_array($sentiment, ['positive', 'neutral', 'any'], true)) {
            http_response_code(400);
            json_response(['error' => "Invalid requested_sentiment. Use 'positive'|'neutral'|'any'."]);
            return;
        }

        $perCap = isset($body['per_provider_cap'])
            ? max(1, min(10, (int) $body['per_provider_cap']))
            : null;

        try {
            $orchestrator = $this->buildOrchestrator();
        } catch (InvalidArgumentException $e) {
            http_response_code(500);
            json_response(['error' => $e->getMessage()]);
            return;
        }

        try {
            $result = $orchestrator->run($gapId, $requestedTiers, $sentiment, $perCap);

            // Stamp the run with the user who triggered it so we can audit
            if (!empty($result['run_id'])) {
                Capsule::table('orbit_search_runs')
                    ->where('id', (int) $result['run_id'])
                    ->update([
                        'triggered_by'         => 'user',
                        'triggered_by_user_id' => $auth->user_id,
                    ]);
            }

            json_response(['success' => true] + $result);
        } catch (RuntimeException $e) {
            http_response_code(404);
            json_response(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            json_response([
                'error'   => 'Orchestrator failure',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/orbit/runs/{id}
     *
     * Returns a previous run's metadata + its ranked results.
     * Tenant-scoped.
     *
     * Path parsing: the route dispatcher in routes/api.php passes the run id
     * through ?id=N or via REQUEST_URI parsing. We support both for safety.
     */
    public function getRun(): void
    {
        $auth = MeridianAuth::require('viewer');

        $runId = $this->extractIdFromUri('runs');
        if ($runId <= 0) {
            http_response_code(400);
            json_response(['error' => 'Invalid run id.']);
            return;
        }

        $run = Capsule::table('orbit_search_runs')->where('id', $runId)->first();
        if (!$run) {
            http_response_code(404);
            json_response(['error' => "Run {$runId} not found."]);
            return;
        }
        if ((int) ($run->agency_id ?? 0) !== $auth->agency_id && !$auth->is_superadmin) {
            http_response_code(403);
            json_response(['error' => 'You do not have access to this run.']);
            return;
        }

        $results = Capsule::table('orbit_search_results')
            ->where('search_run_id', $runId)
            ->orderBy('candidate_score', 'desc')
            ->get();

        $resultsArray = array_map(static function ($r) {
            $r = (array) $r;
            // Don't ship full embeddings or raw_response over the wire by default
            unset($r['candidate_embedding']);
            // Keep raw_response — frontend may want it for debugging
            return $r;
        }, $results->all());

        json_response([
            'success' => true,
            'run' => [
                'id'                  => (int) $run->id,
                'gap_id'              => (int) $run->gap_id,
                'brand_id'            => (int) $run->brand_id,
                'agency_id'           => (int) $run->agency_id,
                'claim_text'          => $run->claim_text,
                'requested_sentiment' => $run->requested_sentiment,
                'requested_tiers'     => $run->requested_tiers,
                'platforms_searched'  => $run->platforms_searched,
                'platforms_skipped'   => $run->platforms_skipped,
                'results_count'       => (int) $run->results_count,
                'accepted_count'      => (int) $run->accepted_count,
                'latency_ms'          => isset($run->latency_ms) ? (int) $run->latency_ms : null,
                'status'              => $run->status,
                'error_message'       => $run->error_message,
                'created_at'          => $run->created_at,
                'completed_at'        => $run->completed_at,
            ],
            'results' => $resultsArray,
        ]);
    }

    /**
     * POST /api/orbit/results/{id}/accept
     *
     * Marks a result as accepted by the current user. Increments
     * accepted_count on the parent run.
     */
    public function acceptResult(): void
    {
        $auth = MeridianAuth::require('analyst');

        $resultId = $this->extractIdFromUri('results');
        if ($resultId <= 0) {
            http_response_code(400);
            json_response(['error' => 'Invalid result id.']);
            return;
        }

        $result = Capsule::table('orbit_search_results')->where('id', $resultId)->first();
        if (!$result) {
            http_response_code(404);
            json_response(['error' => "Result {$resultId} not found."]);
            return;
        }

        // Verify tenant isolation via parent run
        $run = Capsule::table('orbit_search_runs')->where('id', $result->search_run_id)->first();
        if (!$run) {
            http_response_code(404);
            json_response(['error' => 'Parent run missing.']);
            return;
        }
        if ((int) $run->agency_id !== $auth->agency_id && !$auth->is_superadmin) {
            http_response_code(403);
            json_response(['error' => 'You do not have access to this result.']);
            return;
        }

        // Idempotent: only update if not already accepted
        if (!$result->accepted) {
            Capsule::table('orbit_search_results')
                ->where('id', $resultId)
                ->update([
                    'accepted'             => true,
                    'accepted_at'          => Capsule::raw('NOW()'),
                    'accepted_by_user_id'  => $auth->user_id,
                    'rejection_reason'     => null,
                ]);

            Capsule::table('orbit_search_runs')
                ->where('id', $result->search_run_id)
                ->increment('accepted_count');
        }

        json_response([
            'success'   => true,
            'result_id' => $resultId,
            'accepted'  => true,
        ]);
    }

    /**
     * POST /api/orbit/results/{id}/reject
     *
     * Body:
     * {
     *   "reason": "irrelevant" | "low_quality" | "duplicate" | "off_brand" | "other"
     * }
     */
    public function rejectResult(): void
    {
        $auth = MeridianAuth::require('analyst');

        $resultId = $this->extractIdFromUri('results');
        if ($resultId <= 0) {
            http_response_code(400);
            json_response(['error' => 'Invalid result id.']);
            return;
        }

        $body   = $this->readJsonBody();
        $reason = (string) ($body['reason'] ?? 'other');
        $validReasons = ['irrelevant', 'low_quality', 'duplicate', 'off_brand', 'other'];
        if (!in_array($reason, $validReasons, true)) {
            $reason = 'other';
        }

        $result = Capsule::table('orbit_search_results')->where('id', $resultId)->first();
        if (!$result) {
            http_response_code(404);
            json_response(['error' => "Result {$resultId} not found."]);
            return;
        }

        $run = Capsule::table('orbit_search_runs')->where('id', $result->search_run_id)->first();
        if (!$run) {
            http_response_code(404);
            json_response(['error' => 'Parent run missing.']);
            return;
        }
        if ((int) $run->agency_id !== $auth->agency_id && !$auth->is_superadmin) {
            http_response_code(403);
            json_response(['error' => 'You do not have access to this result.']);
            return;
        }

        // If it was previously accepted, decrement the parent counter
        $wasAccepted = (bool) $result->accepted;

        Capsule::table('orbit_search_results')
            ->where('id', $resultId)
            ->update([
                'accepted'             => false,
                'accepted_at'          => null,
                'accepted_by_user_id'  => null,
                'rejection_reason'     => $reason,
            ]);

        if ($wasAccepted) {
            Capsule::table('orbit_search_runs')
                ->where('id', $result->search_run_id)
                ->decrement('accepted_count');
        }

        json_response([
            'success'   => true,
            'result_id' => $resultId,
            'rejected'  => true,
            'reason'    => $reason,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildOrchestrator(): OrbitSearchOrchestrator
    {
        $openaiKey = (string) env('OPENAI_API_KEY', '');
        if ($openaiKey === '') {
            throw new InvalidArgumentException('OPENAI_API_KEY is not set in Railway.');
        }
        $braveKey = (string) env('BRAVE_SEARCH_API_KEY', '');
        if ($braveKey === '') {
            throw new InvalidArgumentException('BRAVE_SEARCH_API_KEY is not set in Railway.');
        }
        $githubToken = (string) env('GITHUB_TOKEN', '');

        return new OrbitSearchOrchestrator(
            embedder:    new EmbeddingService($openaiKey),
            resolver:    new CitationPlatformResolver(),
            scorer:      new CandidateScorer(),
            braveApiKey: $braveKey,
            githubToken: $githubToken !== '' ? $githubToken : null
        );
    }

    /**
     * Extract a numeric ID from the URI path segment immediately after $segment.
     * E.g. for URI '/api/orbit/runs/42' with segment 'runs', returns 42.
     */
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
        // Also check ?id=N for fallback
        if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
            return (int) $_GET['id'];
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
