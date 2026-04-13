<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianAuditController
 *
 * POST /api/meridian/audits/initiate   — Start a new audit (creates records, fires worker)
 * POST /api/meridian/audits/complete   — Internal: worker calls this when finished
 * GET  /api/meridian/audit/status?id=  — Poll audit + probe run status
 * GET  /api/meridian/audit/history     — Past audits for agency
 */
class MeridianAuditController
{
    // Accepted platform keys
    private const PLATFORMS = ['chatgpt', 'gemini', 'perplexity'];

    // Default directed DPA prompts (used if client doesn't supply custom ones)
    private const DEFAULT_PROMPTS = [
        'anchored_t1' => "I've been looking at [Brand] for my [Category] routine. Can you tell me about it?",
        'anchored_t2' => "How does it compare to other products at a similar price point?",
        'anchored_t3' => "I need a product with clinically proven ingredients, visible results within 4 weeks, effective formulation, good value, and strong reviews. Which option is best for me?",
        'anchored_t4' => "Based on everything we've discussed, what would you recommend I buy and where can I get it from?",
        'generic_t1'  => "I'm looking for the best [Category] product with clinically proven ingredients. What would you recommend?",
        'generic_t2'  => "What are the top options in this category right now?",
        'generic_t3'  => "I need something with clinical proof, visible results within 4 weeks, good value, strong reviews, and widely available. Which product fits best?",
        'generic_t4'  => "Based on everything we've discussed, what would you recommend I buy and where can I get it from?",
    ];

    // ── POST /api/meridian/audits/initiate ───────────────────────
    public function initiate(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId   = isset($body['brand_id'])   ? (int)$body['brand_id']  : null;
        $auditType = isset($body['audit_type'])  ? trim($body['audit_type']) : 'full';
        $platforms = isset($body['platforms'])   ? (array)$body['platforms'] : self::PLATFORMS;
        $prompts   = isset($body['prompts'])     ? (array)$body['prompts']   : [];

        // ── Validate brand belongs to this agency ─────────────────
        if (!$brandId) {
            http_response_code(422);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found.']);
            return;
        }

        // ── Validate platforms ────────────────────────────────────
        $platforms = array_filter($platforms, fn($p) => in_array($p, self::PLATFORMS, true));
        if (empty($platforms)) {
            http_response_code(422);
            json_response(['error' => 'At least one valid platform is required (chatgpt, gemini, perplexity).']);
            return;
        }
        $platforms = array_values($platforms);

        // ── Check for already-running audit on this brand ─────────
        $running = DB::table('meridian_audits')
            ->where('brand_id', $brandId)
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($running) {
            http_response_code(409);
            json_response(['error' => 'An audit is already running for this brand.', 'auditId' => $running->id]);
            return;
        }

        // ── Resolve instrument type ───────────────────────────────
        $instrumentType   = trim($body['instrument_type'] ?? $auditType ?? 'directed_bjp');
        $undirectedConfig = $body['undirected_config'] ?? [];
        $validInstruments = ['full', 'directed_bjp', 'undirected_bjp', 'psos'];
        if (!in_array($instrumentType, $validInstruments, true)) {
            $instrumentType = 'directed_bjp';
        }

        // ── Merge custom prompts over defaults ────────────────────
        $resolvedPrompts = self::DEFAULT_PROMPTS;
        foreach ($prompts as $key => $value) {
            if (!empty(trim((string)$value))) {
                $resolvedPrompts[$key] = trim((string)$value);
            }
        }

        // Replace [Brand] and [Category] placeholders
        $brandName = $brand->name;
        $category  = $brand->category ?: 'product';
        foreach ($resolvedPrompts as $key => $val) {
            $resolvedPrompts[$key] = str_replace(
                ['[Brand]', '[Category]', '[category]'],
                [$brandName, $category, strtolower($category)],
                $val
            );
        }

        // ── Determine probe runs based on instrument type ─────────
        // directed_bjp: anchored + generic per platform (4-turn CODA)
        // undirected_bjp: undirected anchored and/or generic per platform (variable turns)
        // psos: single PSOS run (no probe_runs needed — engine handles internally)
        // full: directed_bjp + undirected_bjp across all platforms
        $probeRunsToCreate = [];
        $maxTurns = (int)($undirectedConfig['max_turns'] ?? 8);

        foreach ($platforms as $platform) {
            if (in_array($instrumentType, ['directed_bjp', 'full'], true)) {
                // Directed anchored probe
                $probeRunsToCreate[] = [
                    'platform'   => $platform,
                    'probe_mode' => 'anchored',
                    'instrument' => 'Directed BJP — Anchored',
                    'undirected' => false,
                    'raw_config' => [
                        'prompts'    => $resolvedPrompts,
                        'brand_name' => $brandName,
                        'category'   => $category,
                        'undirected' => false,
                    ],
                ];
                // Directed generic probe
                $probeRunsToCreate[] = [
                    'platform'   => $platform,
                    'probe_mode' => 'generic',
                    'instrument' => 'Directed BJP — Generic',
                    'undirected' => false,
                    'raw_config' => [
                        'prompts'    => $resolvedPrompts,
                        'brand_name' => $brandName,
                        'category'   => $category,
                        'undirected' => false,
                    ],
                ];
            }

            if (in_array($instrumentType, ['undirected_bjp', 'full'], true)) {
                $udirAnchored = (bool)($undirectedConfig['anchored'] ?? true);
                $udirGeneric  = (bool)($undirectedConfig['generic']  ?? false);
                $udirT1       = $resolvedPrompts['undirected_t1']
                    ?? "I've been looking at {$brandName} for my {$category} routine. Can you tell me about it?";

                if ($udirAnchored) {
                    $probeRunsToCreate[] = [
                        'platform'   => $platform,
                        'probe_mode' => 'anchored',
                        'instrument' => 'Undirected BJP — Anchored',
                        'undirected' => true,
                        'raw_config' => [
                            'prompts'      => $resolvedPrompts,
                            'undirected_t1'=> $udirT1,
                            'brand_name'   => $brandName,
                            'category'     => $category,
                            'undirected'   => true,
                            'max_turns'    => $maxTurns,
                        ],
                    ];
                }

                if ($udirGeneric) {
                    $genericT1 = "I'm looking for a recommendation for a {$category}. What would you suggest?";
                    $probeRunsToCreate[] = [
                        'platform'   => $platform,
                        'probe_mode' => 'generic',
                        'instrument' => 'Undirected BJP — Generic',
                        'undirected' => true,
                        'raw_config' => [
                            'prompts'      => $resolvedPrompts,
                            'undirected_t1'=> $genericT1,
                            'brand_name'   => $brandName,
                            'category'     => $category,
                            'undirected'   => true,
                            'max_turns'    => $maxTurns,
                        ],
                    ];
                }
            }
        }

        // PSOS has no individual probe runs — worker handles internally
        $probesTotal = in_array($instrumentType, ['psos'], true) ? 1 : count($probeRunsToCreate);

        // ── Resolve methodology version ───────────────────────────
        $methodologyVersion = DB::table('meridian_methodology_versions')
            ->orderBy('id', 'desc')->first();

        if (!$methodologyVersion) {
            $methodologyVersionId = DB::table('meridian_methodology_versions')->insertGetId([
                'name' => 'AIVO Meridian v1', 'version' => '1.0',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $methodologyVersionId = $methodologyVersion->id;
        }

        try {
            DB::beginTransaction();

            $auditId = DB::table('meridian_audits')->insertGetId([
                'agency_id'              => $auth->agency_id,
                'client_id'              => $brand->client_id ?? null,
                'brand_id'               => $brandId,
                'audit_type'             => $instrumentType,
                'status'                 => 'queued',
                'initiated_by_user_id'   => $auth->user->id,
                'initiated_by'           => $auth->user->email,
                'methodology_version_id' => $methodologyVersionId,
                'platforms'              => json_encode($platforms),
                'probes_total'           => $probesTotal,
                'probes_completed'       => 0,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            foreach ($probeRunsToCreate as $pr) {
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
                    'raw_config'             => json_encode($pr['raw_config']),
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            log_error('[Meridian] audit initiate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to create audit. Please try again.']);
            return;
        }

        // ── Fire background worker ────────────────────────────────
        // PHP_BINARY gives path to current PHP executable — safe on Railway
        $workerScript = realpath(__DIR__ . '/../../workers/run_audit.php');
        if ($workerScript && file_exists($workerScript)) {
            $cmd = PHP_BINARY . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string)$auditId)
                . ' > /dev/null 2>&1 &';
            exec($cmd);
        } else {
            log_error('[Meridian] worker script not found', ['expected' => __DIR__ . '/../../workers/run_audit.php']);
        }

        json_response([
            'status'      => 'queued',
            'auditId'     => $auditId,
            'probesTotal' => $probesTotal,
            'platforms'   => $platforms,
            'brandName'   => $brandName,
        ]);
    }

    // ── GET /api/meridian/audit/status?id= ──────────────────────
    public function status(): void
    {
        $auth    = MeridianAuth::require();
        $auditId = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$auditId) {
            http_response_code(422);
            json_response(['error' => 'Audit id is required.']);
            return;
        }

        $audit = DB::table('meridian_audits')
            ->where('id', $auditId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$audit) {
            http_response_code(404);
            json_response(['error' => 'Audit not found.']);
            return;
        }

        $probeRuns = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)
            ->orderBy('platform')
            ->orderBy('probe_mode')
            ->get();

        $runsOut = $probeRuns->map(function ($run) {
            return [
                'id'             => (int)$run->id,
                'platform'       => $run->platform,
                'probeMode'      => $run->probe_mode,
                'instrument'     => $run->probe_mode === 'anchored' ? 'DPA Anchored' : 'DPA Generic',
                'status'         => $run->status,
                'turnsCompleted' => (int)$run->turns_completed,
                'ditTurn'        => $run->dit_turn ? (int)$run->dit_turn : null,
                't4Winner'       => $run->t4_winner,
                'errorMessage'   => $run->error_message,
            ];
        })->toArray();

        json_response([
            'audit' => [
                'id'              => (int)$audit->id,
                'status'          => $audit->status,
                'percentComplete' => $audit->probes_total > 0
                    ? (int)round(($audit->probes_completed / $audit->probes_total) * 100)
                    : 0,
                'probesCompleted' => (int)$audit->probes_completed,
                'probesTotal'     => (int)$audit->probes_total,
                'errorMessage'    => $audit->error_message,
                'startedAt'       => $audit->started_at,
                'completedAt'     => $audit->completed_at,
            ],
            'probeRuns' => $runsOut,
        ]);
    }

    // ── GET /api/meridian/audit/history ─────────────────────────
    public function history(): void
    {
        $auth     = MeridianAuth::require();
        $brandId  = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $limit    = min((int)($_GET['limit'] ?? 20), 50);

        $query = DB::table('meridian_audits')
            ->where('meridian_audits.agency_id', $auth->agency_id)
            ->join('meridian_brands', 'meridian_brands.id', '=', 'meridian_audits.brand_id')
            ->select(
                'meridian_audits.id',
                'meridian_audits.brand_id',
                'meridian_audits.status',
                'meridian_audits.audit_type',
                'meridian_audits.platforms',
                'meridian_audits.probes_total',
                'meridian_audits.probes_completed',
                'meridian_audits.percent_complete',
                'meridian_audits.started_at',
                'meridian_audits.completed_at',
                'meridian_audits.created_at',
                'meridian_brands.name as brand_name'
            )
            ->orderByDesc('meridian_audits.created_at')
            ->limit($limit);

        if ($brandId) {
            $query->where('meridian_audits.brand_id', $brandId);
        }

        $audits = $query->get();

        json_response([
            'audits' => $audits->map(function ($a) {
                return [
                    'id'              => (int)$a->id,
                    'brandId'         => (int)$a->brand_id,
                    'brandName'       => $a->brand_name,
                    'status'          => $a->status,
                    'auditType'       => $a->audit_type,
                    'platforms'       => json_decode($a->platforms ?? '[]', true),
                    'probesTotal'     => (int)$a->probes_total,
                    'probesCompleted' => (int)$a->probes_completed,
                    'percentComplete' => (int)$a->percent_complete,
                    'startedAt'       => $a->started_at,
                    'completedAt'     => $a->completed_at,
                    'createdAt'       => $a->created_at,
                ];
            })->toArray(),
        ]);
    }

    // ── POST /api/meridian/audits/complete ───────────────────────
    // Called internally by the worker when all probes finish.
    // Validates using MERIDIAN_INTERNAL_SECRET header.
    public function complete(): void
    {
        $secret = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
        if ($secret !== env('MERIDIAN_INTERNAL_SECRET')) {
            http_response_code(403);
            json_response(['error' => 'Forbidden.']);
            return;
        }

        $body    = request_body();
        $auditId = isset($body['audit_id']) ? (int)$body['audit_id'] : null;

        if (!$auditId) {
            http_response_code(422);
            json_response(['error' => 'audit_id required.']);
            return;
        }

        // The worker handles most of the DB updates itself.
        // This endpoint exists for any post-completion hooks needed in future.
        json_response(['status' => 'ok']);
    }
}
