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
     * Body: { "audit_id": 123 }
     *
     * Classifies every platform × probe_mode combination that has a completed
     * (or any status) probe run for this audit. A Full Suite audit produces
     * anchored + generic modes per platform, so this generates up to 6
     * classifications rather than just 3 — giving a complete picture of
     * where the brand is displaced differently across modes.
     *
     * Results are keyed as "{platform}_{mode}" e.g. "chatgpt_anchored".
     */
    public function classifyAll(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $auditId = isset($body['audit_id']) ? (int)$body['audit_id'] : null;

        if (!$auditId) {
            http_response_code(400);
            json_response(['error' => 'audit_id is required.']);
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

        // Get all distinct platform × probe_mode combinations for this audit.
        // Prefer completed runs but fall back to any status so the classifier's
        // journey_runs fallback path can handle incomplete probe data.
        $probeRuns = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->whereIn('platform', ['chatgpt', 'gemini', 'perplexity'])
            ->select('platform', 'probe_mode')
            ->distinct()
            ->get();

        if ($probeRuns->isEmpty()) {
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
                $results[$key] = $classifier->classifyByMode($auditId, $platform, $probeMode);
            } catch (\Throwable $e) {
                $errors[$key] = $e->getMessage();
                log_error('[MeridianClassifier] classifyAll error', [
                    'platform'  => $platform,
                    'probe_mode'=> $probeMode,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        json_response([
            'success' => count($results) > 0,
            'data'    => $results,
            'errors'  => $errors,
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

        $rows = DB::table('meridian_filter_classifications')
            ->where('audit_id', $auditId)
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

        json_response(['success' => true, 'data' => $out]);
    }
}
