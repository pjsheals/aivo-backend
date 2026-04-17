<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianReprobeController — Module 8
 *
 * Initiates a post-publication re-probe and calculates RCS delta + turn delta.
 *
 * POST /api/meridian/reprobe/initiate   — Trigger a re-probe audit
 * GET  /api/meridian/reprobe/delta      — Get RCS delta + per-platform turn delta
 * GET  /api/meridian/reprobe/history    — List all re-probes for a brand
 *
 * Stage 6 additions:
 *   - turn_delta_data: per-platform DIT (displacement inflection turn) before vs after.
 *     Positive delta = brand survived longer = atoms working.
 *     Requires M1 classifier to have run on both baseline and re-probe audits.
 */
class MeridianReprobeController
{
    private const REPROBE_LABEL  = 'reprobe';
    private const PROBE_PLATFORMS = ['chatgpt', 'gemini', 'perplexity'];

    /**
     * POST /api/meridian/reprobe/initiate
     * Body: { "brand_id": 2, "platforms": ["chatgpt","gemini","perplexity"] (optional) }
     */
    public function initiate(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId   = isset($body['brand_id']) ? (int)$body['brand_id'] : null;
        $platforms = isset($body['platforms']) ? (array)$body['platforms'] : self::PROBE_PLATFORMS;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $running = DB::table('meridian_audits')
            ->where('brand_id', $brandId)
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($running) {
            http_response_code(409);
            json_response(['error' => 'An audit is already running for this brand.', 'audit_id' => $running->id]);
            return;
        }

        // Baseline = most recent completed non-reprobe audit
        $baseline = DB::table('meridian_audits')
            ->where('brand_id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->where('status', 'completed')
            ->where('initiated_by', '!=', self::REPROBE_LABEL)
            ->orderByDesc('completed_at')
            ->first();

        if (!$baseline) {
            http_response_code(422);
            json_response(['error' => 'No completed baseline audit found. Run a full audit first.']);
            return;
        }

        $baselineResult = DB::table('meridian_brand_audit_results')
            ->where('audit_id', $baseline->id)
            ->first();

        $baselineRcs = $baselineResult ? (int)$baselineResult->rcs_total : null;

        $methodologyVersion   = DB::table('meridian_methodology_versions')->orderBy('id', 'desc')->first();
        $methodologyVersionId = $methodologyVersion ? $methodologyVersion->id : null;

        $brandName = $brand->name;
        $category  = $brand->category ?: 'product';

        $resolvedPrompts = [
            'anchored_t1' => "I've been looking at {$brandName} for my {$category} routine. Can you tell me about it?",
            'anchored_t2' => "How does it compare to other products at a similar price point?",
            'anchored_t3' => "I need a product with clinically proven ingredients, visible results within 4 weeks, effective formulation, good value, and strong reviews. Which option is best for me?",
            'anchored_t4' => "Based on everything we've discussed, what would you recommend I buy and where can I get it from?",
            'generic_t1'  => "I'm looking for the best {$category} product with clinically proven ingredients. What would you recommend?",
            'generic_t2'  => "What are the top options in this category right now?",
            'generic_t3'  => "I need something with clinical proof, visible results within 4 weeks, good value, strong reviews, and widely available. Which product fits best?",
            'generic_t4'  => "Based on everything we've discussed, what would you recommend I buy and where can I get it from?",
        ];

        $validPlatforms = array_values(array_filter($platforms, fn($p) => in_array($p, self::PROBE_PLATFORMS, true)));
        if (empty($validPlatforms)) $validPlatforms = self::PROBE_PLATFORMS;

        $probeRuns = [];
        foreach ($validPlatforms as $platform) {
            foreach (['anchored', 'generic'] as $mode) {
                $probeRuns[] = [
                    'platform'   => $platform,
                    'probe_mode' => $mode,
                    'instrument' => $mode === 'anchored' ? 'BJP-D Anchored' : 'BJP-D Generic',
                    'raw_config' => json_encode([
                        'prompts'    => $resolvedPrompts,
                        'brand_name' => $brandName,
                        'category'   => $category,
                        'undirected' => false,
                    ]),
                ];
            }
        }

        try {
            DB::beginTransaction();

            $auditId = DB::table('meridian_audits')->insertGetId([
                'agency_id'              => $auth->agency_id,
                'client_id'              => $brand->client_id ?? null,
                'brand_id'               => $brandId,
                'audit_type'             => 'directed_bjp',
                'status'                 => 'queued',
                'initiated_by_user_id'   => $auth->user_id,
                'initiated_by'           => self::REPROBE_LABEL,
                'methodology_version_id' => $methodologyVersionId,
                'platforms'              => json_encode($validPlatforms),
                'probes_total'           => count($probeRuns),
                'probes_completed'       => 0,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            foreach ($probeRuns as $pr) {
                DB::table('meridian_probe_runs')->insert([
                    'audit_id'               => $auditId,
                    'brand_id'               => $brandId,
                    'agency_id'              => $auth->agency_id,
                    'methodology_version_id' => $methodologyVersionId,
                    'instrument'             => $pr['instrument'],
                    'platform'               => $pr['platform'],
                    'probe_mode'             => $pr['probe_mode'],
                    'status'                 => 'queued',
                    'turns_completed'        => 0,
                    'raw_config'             => $pr['raw_config'],
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
            }

            $reprobeId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            DB::table('meridian_reprobe_results')->insert([
                'id'                => $reprobeId,
                'brand_id'          => $brandId,
                'agency_id'         => $auth->agency_id,
                'audit_id'          => $auditId,
                'baseline_audit_id' => $baseline->id,
                'baseline_rcs'      => $baselineRcs,
                'reprobe_rcs'       => null,
                'rcs_delta'         => null,
                'turn_delta_data'   => null,
                'status'            => 'running',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            log_error('[M8] reprobe initiate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to initiate re-probe.']);
            return;
        }

        $workerScript = realpath(__DIR__ . '/../../workers/run_audit.php');
        if ($workerScript && file_exists($workerScript)) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string)$auditId)
                . ' > /dev/null 2>&1 &';
            exec($cmd);
        }

        json_response([
            'success' => true,
            'data'    => [
                'reprobe_id'   => $reprobeId,
                'audit_id'     => $auditId,
                'brand_id'     => $brandId,
                'baseline_rcs' => $baselineRcs,
                'status'       => 'running',
                'platforms'    => $validPlatforms,
                'probes_total' => count($probeRuns),
            ],
        ]);
    }

    /**
     * GET /api/meridian/reprobe/delta?brand_id=X
     *
     * Returns RCS delta + per-platform turn delta for the most recent re-probe.
     * Turn delta = reprobe dit_turn minus baseline dit_turn per platform.
     * Positive = brand survived longer = atoms working.
     * Requires M1 classifier to have run on both audits.
     */
    public function delta(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $reprobe = DB::table('meridian_reprobe_results')
            ->where('brand_id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->orderByDesc('created_at')
            ->first();

        if (!$reprobe) {
            json_response(['success' => true, 'data' => null, 'message' => 'No re-probe found for this brand.']);
            return;
        }

        $audit = DB::table('meridian_audits')->find($reprobe->audit_id);

        // ── RCS delta ────────────────────────────────────────────
        if ($reprobe->rcs_delta === null && $audit && $audit->status === 'completed') {
            $result = DB::table('meridian_brand_audit_results')
                ->where('audit_id', $reprobe->audit_id)
                ->first();

            if ($result) {
                $reprobeRcs = (int)$result->rcs_total;
                $rcsDelta   = $reprobeRcs - (int)$reprobe->baseline_rcs;

                DB::table('meridian_reprobe_results')
                    ->where('id', $reprobe->id)
                    ->update([
                        'reprobe_rcs' => $reprobeRcs,
                        'rcs_delta'   => $rcsDelta,
                        'updated_at'  => now(),
                    ]);

                $reprobe->reprobe_rcs = $reprobeRcs;
                $reprobe->rcs_delta   = $rcsDelta;
            }
        }

        // ── Turn delta ───────────────────────────────────────────
        // Calculate if not yet stored and audit is complete
        $turnDeltaData = $reprobe->turn_delta_data
            ? json_decode($reprobe->turn_delta_data, true)
            : null;

        if ($turnDeltaData === null && $audit && $audit->status === 'completed') {
            $turnDeltaData = $this->calculateTurnDelta(
                (int)$reprobe->baseline_audit_id,
                (int)$reprobe->audit_id
            );

            if ($turnDeltaData !== null) {
                DB::table('meridian_reprobe_results')
                    ->where('id', $reprobe->id)
                    ->update([
                        'turn_delta_data' => json_encode($turnDeltaData),
                        'status'          => 'completed',
                        'updated_at'      => now(),
                    ]);
                $reprobe->status = 'completed';
            }
        }

        // If both RCS and turn are resolved, mark completed
        if ($reprobe->rcs_delta !== null
            && $reprobe->status !== 'completed'
            && $audit && $audit->status === 'completed'
        ) {
            DB::table('meridian_reprobe_results')
                ->where('id', $reprobe->id)
                ->update(['status' => 'completed', 'updated_at' => now()]);
            $reprobe->status = 'completed';
        }

        json_response([
            'success' => true,
            'data'    => [
                'id'              => $reprobe->id,
                'brand_id'        => $brandId,
                'brand_name'      => $brand->name,
                'baseline_rcs'    => $reprobe->baseline_rcs,
                'reprobe_rcs'     => $reprobe->reprobe_rcs,
                'rcs_delta'       => $reprobe->rcs_delta,
                'delta_label'     => $this->deltaLabel($reprobe->rcs_delta),
                'turn_delta_data' => $turnDeltaData,
                'turn_delta_available' => $turnDeltaData !== null,
                'status'          => $reprobe->status,
                'audit_id'        => $reprobe->audit_id,
                'baseline_audit_id' => $reprobe->baseline_audit_id,
                'created_at'      => $reprobe->created_at,
            ],
        ]);
    }

    /**
     * GET /api/meridian/reprobe/history?brand_id=X
     */
    public function history(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $reprobes = DB::table('meridian_reprobe_results')
            ->where('brand_id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->orderByDesc('created_at')
            ->get();

        json_response([
            'success' => true,
            'data'    => $reprobes->map(fn($r) => [
                'id'              => $r->id,
                'baseline_rcs'    => $r->baseline_rcs,
                'reprobe_rcs'     => $r->reprobe_rcs,
                'rcs_delta'       => $r->rcs_delta,
                'delta_label'     => $this->deltaLabel($r->rcs_delta),
                'turn_delta_data' => $r->turn_delta_data ? json_decode($r->turn_delta_data, true) : null,
                'status'          => $r->status,
                'audit_id'        => $r->audit_id,
                'created_at'      => $r->created_at,
            ])->toArray(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Turn delta calculation
    // -------------------------------------------------------------------------

    /**
     * Compare M1 filter_classifications between baseline and re-probe audits.
     * Returns per-platform { baseline_dit, reprobe_dit, delta, baseline_t4, reprobe_t4 }.
     * Returns null if no classifications exist for the re-probe audit yet.
     */
    private function calculateTurnDelta(int $baselineAuditId, int $reprobeAuditId): ?array
    {
        // Get re-probe classifications — if none exist, classifier hasn't run yet
        $reprobeClassifications = DB::table('meridian_filter_classifications')
            ->where('audit_id', $reprobeAuditId)
            ->get()
            ->keyBy('platform');

        if ($reprobeClassifications->isEmpty()) {
            return null;
        }

        $baselineClassifications = DB::table('meridian_filter_classifications')
            ->where('audit_id', $baselineAuditId)
            ->get()
            ->keyBy('platform');

        $result = [];

        foreach (self::PROBE_PLATFORMS as $platform) {
            $baseline = $baselineClassifications->get($platform);
            $reprobe  = $reprobeClassifications->get($platform);

            $baselineDit = $baseline ? ($baseline->dit_turn  !== null ? (int)$baseline->dit_turn  : null) : null;
            $reprobeDit  = $reprobe  ? ($reprobe->dit_turn   !== null ? (int)$reprobe->dit_turn    : null) : null;
            $delta       = ($baselineDit !== null && $reprobeDit !== null) ? ($reprobeDit - $baselineDit) : null;

            $result[$platform] = [
                'baseline_dit'      => $baselineDit,
                'reprobe_dit'       => $reprobeDit,
                'delta'             => $delta,
                'baseline_t4'       => $baseline ? ($baseline->t4_winner ?? null) : null,
                'reprobe_t4'        => $reprobe  ? ($reprobe->t4_winner  ?? null) : null,
                'delta_label'       => $this->turnDeltaLabel($delta),
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function deltaLabel(?int $delta): string
    {
        if ($delta === null) return 'pending';
        if ($delta > 10)  return 'significant_improvement';
        if ($delta > 0)   return 'marginal_improvement';
        if ($delta === 0) return 'no_change';
        if ($delta > -10) return 'marginal_decline';
        return 'significant_decline';
    }

    private function turnDeltaLabel(?int $delta): string
    {
        if ($delta === null) return 'pending';
        if ($delta > 0)   return 'survived_longer';
        if ($delta === 0) return 'no_change';
        return 'earlier_displacement';
    }
}
