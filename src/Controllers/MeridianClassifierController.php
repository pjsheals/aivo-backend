<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianFilterClassifier;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianClassifierController
{
    /**
     * POST /api/meridian/classify
     * Body: { "audit_id": 123, "platform": "gemini" }
     */
    public function classify(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $auditId  = isset($body['audit_id'])  ? (int)$body['audit_id']             : null;
        $platform = isset($body['platform'])  ? strtolower(trim($body['platform'])) : null;

        if (!$auditId || !$platform) {
            http_response_code(400);
            json_response(['error' => 'audit_id and platform are required.']);
            return;
        }

        if (!in_array($platform, ['chatgpt', 'gemini', 'perplexity'], true)) {
            http_response_code(400);
            json_response(['error' => 'platform must be one of: chatgpt, gemini, perplexity.']);
            return;
        }

        $audit = DB::table('meridian_audits')
            ->where('id', $auditId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$audit) {
            http_response_code(403);
            json_response(['error' => 'Audit not found or access denied.']);
            return;
        }

        try {
            $classifier = new MeridianFilterClassifier();
            $result     = $classifier->classify($auditId, $platform);
            json_response(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            json_response(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_error('[MeridianClassifier] classify error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/classify/all
     * Body: { "audit_id": 123, "brand_id": 9 }
     *
     * Classifies every platform × probe_mode combination that has a probe run
     * for this audit. If the requested audit is PSOS-only (no BJP probe runs),
     * automatically falls back to the most recent audit for the same brand
     * that DOES have BJP probe runs. This is transparent to the caller —
     * the response includes a `classified_audit_id` field so the frontend
     * knows which audit was actually used.
     */
    public function classifyAll(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $requestedAuditId = isset($body['audit_id']) ? (int)$body['audit_id'] : null;
        $brandId          = isset($body['brand_id']) ? (int)$body['brand_id'] : null;

        if (!$requestedAuditId) {
            http_response_code(400);
            json_response(['error' => 'audit_id is required.']);
            return;
        }

        // Verify the requested audit exists and belongs to this agency.
        $audit = DB::table('meridian_audits')
            ->where('id', $requestedAuditId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$audit) {
            http_response_code(403);
            json_response(['error' => 'Audit not found or access denied.']);
            return;
        }

        // Determine which audit to actually classify against.
        // Prefer the requested one; if it has no BJP probe runs (e.g. it's a
        // PSOS-only audit), fall back to the most recent audit for the same
        // brand that has BJP probe runs.
        $effectiveAuditId = $this->resolveAuditWithProbeRuns(
            $requestedAuditId,
            (int)$audit->brand_id,
            (int)$auth->agency_id
        );

        if ($effectiveAuditId === null) {
            json_response([
                'success' => false,
                'error'   => 'No Buying Journey Probe runs found for this brand. Run a Full Suite or Directed BJP audit before running M1 classification.',
                'data'    => [],
                'errors'  => [],
            ]);
            return;
        }

        // Get all distinct platform × probe_mode combinations for the effective audit.
        $probeRuns = DB::table('meridian_probe_runs')
            ->where('audit_id', $effectiveAuditId)
            ->whereIn('platform', ['chatgpt', 'gemini', 'perplexity'])
            ->select('platform', 'probe_mode')
            ->distinct()
            ->get();

        if ($probeRuns->isEmpty()) {
            // Shouldn't happen given resolveAuditWithProbeRuns returned a non-null ID,
            // but guard defensively.
            json_response([
                'success' => false,
                'error'   => 'No probe runs found for this audit.',
                'data'    => [],
                'errors'  => [],
            ]);
            return;
        }

        $classifier = new MeridianFilterClassifier();
        $results    = [];
        $errors     = [];

        foreach ($probeRuns as $run) {
            $platform  = $run->platform;
            $probeMode = $run->probe_mode;
            $key       = $platform . '_' . $probeMode;

            try {
                $results[$key] = $classifier->classifyByMode($effectiveAuditId, $platform, $probeMode);
            } catch (\Throwable $e) {
                $errors[$key] = $e->getMessage();
                log_error('[MeridianClassifier] classifyAll error', [
                    'audit_id'   => $effectiveAuditId,
                    'platform'   => $platform,
                    'probe_mode' => $probeMode,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        json_response([
            'success'               => count($results) > 0,
            'data'                  => $results,
            'errors'                => $errors,
            'requested_audit_id'    => $requestedAuditId,
            'classified_audit_id'   => $effectiveAuditId,
            'fallback_used'         => $effectiveAuditId !== $requestedAuditId,
        ]);
    }

    /**
     * GET /api/meridian/classify?audit_id=123
     */
    public function getClassifications(): void
    {
        $auth    = MeridianAuth::require();
        $auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : null;

        if (!$auditId) {
            http_response_code(400);
            json_response(['error' => 'audit_id query parameter is required.']);
            return;
        }

        $audit = DB::table('meridian_audits')
            ->where('id', $auditId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$audit) {
            http_response_code(403);
            json_response(['error' => 'Audit not found or access denied.']);
            return;
        }

        // Resolve to the audit that actually has classifications — same fallback
        // pattern as classifyAll so the UI shows the same data.
        $effectiveAuditId = $this->resolveAuditWithProbeRuns(
            $auditId,
            (int)$audit->brand_id,
            (int)$auth->agency_id
        ) ?? $auditId;

        $rows = DB::table('meridian_filter_classifications')
            ->where('audit_id', $effectiveAuditId)
            ->orderByDesc('created_at')
            ->get();

        $out = $rows->map(function ($row) {
            return [
                'id'                     => $row->id,
                'platform'               => $row->platform,
                'probe_type'             => $row->probe_type,
                'primary_filter'         => $row->primary_filter,
                'secondary_filters'      => json_decode($row->secondary_filters ?? '[]', true),
                'reasoning_stage'        => $row->reasoning_stage,
                'displacement_mechanism' => $row->displacement_mechanism,
                'confidence_score'       => $row->confidence_score,
                'evidence_gaps'          => json_decode($row->evidence_gaps ?? '[]', true),
                'evidence_briefs'        => json_decode($row->evidence_briefs ?? '[]', true),
                'brand_story_frame'      => $row->brand_story_frame,
                'reasoning_chain'        => json_decode($row->reasoning_chain ?? '[]', true),
                'dit_turn'               => $row->dit_turn,
                't4_winner'              => $row->t4_winner,
                'created_at'             => $row->created_at,
            ];
        })->toArray();

        json_response([
            'success'               => true,
            'data'                  => $out,
            'requested_audit_id'    => $auditId,
            'classified_audit_id'   => $effectiveAuditId,
            'fallback_used'         => $effectiveAuditId !== $auditId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the audit ID that should actually be classified. If the requested
     * audit has BJP probe runs (chatgpt/gemini/perplexity), return it unchanged.
     * Otherwise find the most recent audit for the same brand/agency that has
     * BJP probe runs. Returns null if no audit for this brand has BJP runs.
     */
    private function resolveAuditWithProbeRuns(int $requestedAuditId, int $brandId, int $agencyId): ?int
    {
        $hasRuns = DB::table('meridian_probe_runs')
            ->where('audit_id', $requestedAuditId)
            ->whereIn('platform', ['chatgpt', 'gemini', 'perplexity'])
            ->exists();

        if ($hasRuns) {
            return $requestedAuditId;
        }

        // Find the most recent audit for this brand (scoped to this agency)
        // that has at least one BJP probe run.
        $fallback = DB::table('meridian_audits as a')
            ->join('meridian_probe_runs as pr', 'pr.audit_id', '=', 'a.id')
            ->where('a.brand_id', $brandId)
            ->where('a.agency_id', $agencyId)
            ->whereIn('pr.platform', ['chatgpt', 'gemini', 'perplexity'])
            ->orderByDesc('a.created_at')
            ->select('a.id')
            ->first();

        return $fallback ? (int)$fallback->id : null;
    }
}
