<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianDashboardController
 *
 * GET /api/meridian/dashboard         — Portfolio overview stats + brand cards
 * GET /api/meridian/dashboard/brands  — All brands with latest scores (sortable)
 */
class MeridianDashboardController
{
    // ── GET /api/meridian/dashboard ──────────────────────────────
    public function overview(): void
    {
        $auth     = MeridianAuth::require('viewer');
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;

        try {
            $brandQuery = DB::table('meridian_brands')
                ->where('agency_id', $auth->agency_id)
                ->where('brand_type', 'monitored')
                ->where('is_active', true)
                ->whereNull('deleted_at');

            if ($clientId) {
                $brandQuery->where('client_id', $clientId);
            }

            $brands = $brandQuery->get();

            // Headline stats
            $totalBrands        = $brands->count();
            $totalClients       = DB::table('meridian_clients')
                ->where('agency_id', $auth->agency_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count();

            $avgRcs = $brands->whereNotNull('current_rcs')->avg('current_rcs');
            $totalRar = $brands->sum('current_rar');

            // Verdict breakdown
            $amplificationReady = $brands->where('current_ad_verdict', 'amplification_ready')->count();
            $monitor            = $brands->where('current_ad_verdict', 'monitor')->count();
            $doNotAdvertise     = $brands->where('current_ad_verdict', 'do_not_advertise')->count();

            // Total platform slots (brands × 4 platforms)
            $totalPlatformSlots     = $totalBrands * 4;
            $platformSlotsReady     = $amplificationReady * 2; // Approximation — refined by actual data

            // Brands needing attention (RCS < 40 or no audit yet)
            $attentionRequired = $brands->filter(
                fn($b) => $b->current_rcs === null || (int)$b->current_rcs < 40
            )->count();

            // Recent audit activity (last 7 days)
            $recentAudits = DB::table('meridian_audits')
                ->where('agency_id', $auth->agency_id)
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->count();

            // Audits due this month (re-audit schedules)
            $auditsDue = DB::table('meridian_reaudit_schedules as s')
                ->join('meridian_brands as b', 'b.id', '=', 's.brand_id')
                ->where('s.agency_id', $auth->agency_id)
                ->where('s.is_active', true)
                ->where('s.next_run_at', '<=', date('Y-m-d H:i:s', strtotime('+30 days')))
                ->count();

            // DIT movement alerts (last 30 days — brands where DIT moved earlier)
            $ditAlerts = DB::table('meridian_dit_events')
                ->where('agency_id', $auth->agency_id)
                ->where('recorded_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->where('dit_movement', '<', 0)  // Negative = DIT moved earlier = deterioration
                ->count();

            json_response([
                'status' => 'ok',
                'stats'  => [
                    'totalBrands'        => $totalBrands,
                    'totalClients'       => $totalClients,
                    'avgRcs'             => $avgRcs ? (int)round($avgRcs) : null,
                    'totalRar'           => $totalRar ? round((float)$totalRar, 2) : null,
                    'amplificationReady' => $amplificationReady,
                    'monitor'            => $monitor,
                    'doNotAdvertise'     => $doNotAdvertise,
                    'attentionRequired'  => $attentionRequired,
                    'platformSlotsReady' => $platformSlotsReady,
                    'totalPlatformSlots' => $totalPlatformSlots,
                    'recentAudits'       => $recentAudits,
                    'auditsDue'          => $auditsDue,
                    'ditAlerts'          => $ditAlerts,
                ],
                'agency' => [
                    'name'      => $auth->agency->name,
                    'planType'  => $auth->agency->plan_type,
                    'maxBrands' => $auth->agency->max_brands,
                    'maxClients'=> $auth->agency->max_clients,
                ],
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian] dashboard.overview error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/dashboard/brands ───────────────────────
    // Returns all brand cards for the portfolio grid view.
    // Supports sort and filter params.
    public function brands(): void
    {
        $auth     = MeridianAuth::require('viewer');
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id']  : null;
        $sortBy   = $_GET['sort']   ?? 'name';       // name|rcs|rar|last_audit|verdict
        $order    = $_GET['order']  ?? 'asc';
        $verdict  = $_GET['verdict'] ?? null;         // Filter by verdict

        $validSorts = ['name', 'current_rcs', 'current_rar', 'last_audited_at', 'current_ad_verdict'];
        if (!in_array($sortBy, $validSorts)) $sortBy = 'name';
        if (!in_array($order, ['asc', 'desc'])) $order = 'asc';

        try {
            $query = DB::table('meridian_brands as b')
                ->join('meridian_clients as c', 'c.id', '=', 'b.client_id')
                ->where('b.agency_id', $auth->agency_id)
                ->where('b.brand_type', 'monitored')
                ->where('b.is_active', true)
                ->whereNull('b.deleted_at');

            if ($clientId) {
                $query->where('b.client_id', $clientId);
            }

            if ($verdict) {
                $query->where('b.current_ad_verdict', $verdict);
            }

            $brands = $query
                ->select([
                    'b.id', 'b.name', 'b.category', 'b.subcategory', 'b.market',
                    'b.current_rcs', 'b.current_rar', 'b.current_ad_verdict',
                    'b.current_dit_chatgpt', 'b.current_dit_gemini',
                    'b.current_dit_perplexity', 'b.current_dit_grok',
                    'b.last_audited_at', 'b.next_reaudit_at', 'b.client_id',
                    'c.name as client_name',
                ])
                ->orderBy('b.' . $sortBy, $order)
                ->get();

            // For each brand, get the latest audit status and any running audit
            $result = $brands->map(function ($b) {
                $runningAudit = DB::table('meridian_audits')
                    ->where('brand_id', $b->id)
                    ->whereIn('status', ['queued', 'running'])
                    ->orderByDesc('created_at')
                    ->first(['id', 'status', 'probes_completed', 'probes_total']);

                // Platform DIT summary for chips
                $platforms = [];
                foreach (['chatgpt', 'gemini', 'perplexity', 'grok'] as $p) {
                    $ditCol = 'current_dit_' . $p;
                    $platforms[] = [
                        'platform' => $p,
                        'dit'      => $b->$ditCol !== null ? (int)$b->$ditCol : null,
                    ];
                }

                return [
                    'id'               => (int)$b->id,
                    'clientId'         => (int)$b->client_id,
                    'clientName'       => $b->client_name,
                    'name'             => $b->name,
                    'category'         => $b->category,
                    'subcategory'      => $b->subcategory,
                    'market'           => $b->market,
                    'currentRcs'       => $b->current_rcs !== null ? (int)$b->current_rcs : null,
                    'currentRar'       => $b->current_rar  ? (float)$b->current_rar        : null,
                    'adVerdict'        => $b->current_ad_verdict,
                    'platforms'        => $platforms,
                    'lastAuditedAt'    => $b->last_audited_at,
                    'nextReauditAt'    => $b->next_reaudit_at,
                    'runningAudit'     => $runningAudit ? [
                        'id'               => (int)$runningAudit->id,
                        'status'           => $runningAudit->status,
                        'probesCompleted'  => (int)$runningAudit->probes_completed,
                        'probesTotal'      => (int)$runningAudit->probes_total,
                    ] : null,
                ];
            });

            json_response(['status' => 'ok', 'brands' => $result->values()]);

        } catch (\Throwable $e) {
            log_error('[Meridian] dashboard.brands error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }
}


/**
 * MeridianAuditController
 *
 * POST /api/meridian/audits/initiate  — Initiate a new audit
 * GET  /api/meridian/audit/status     — Poll audit status (?id=X)
 * GET  /api/meridian/audit/history    — Audit history for brand (?brand_id=X)
 * POST /api/meridian/audits/store-probe-run  — Store probe run result (called by probe engine)
 * POST /api/meridian/audits/store-turn       — Store a single probe turn
 * POST /api/meridian/audits/store-annotation — Store turn annotation
 * POST /api/meridian/audits/complete         — Mark audit complete, compute RCS/RAR
 */
class MeridianAuditController
{
    // ── POST /api/meridian/audits/initiate ───────────────────────
    public function initiate(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();

        $brandId   = (int)($body['brand_id']   ?? 0);
        $auditType = trim($body['audit_type'] ?? 'full');
        $platforms = $body['platforms'] ?? ['chatgpt', 'gemini', 'perplexity'];

        if (!$brandId) {
            http_response_code(422);
            json_response(['error' => 'Brand ID is required.']);
            return;
        }

        $validTypes = ['full', 'psos', 'dpa', 'journey', 'citation'];
        if (!in_array($auditType, $validTypes)) $auditType = 'full';

        // Verify brand belongs to agency
        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found.']);
            return;
        }

        // Check for already-running audit on this brand
        $running = DB::table('meridian_audits')
            ->where('brand_id', $brandId)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($running) {
            http_response_code(409);
            json_response(['error' => 'An audit is already running for this brand.']);
            return;
        }

        // Check monthly audit allowance
        if ($auth->agency->monthly_audit_allowance !== null) {
            $usedThisMonth = DB::table('meridian_audits')
                ->where('agency_id', $auth->agency_id)
                ->whereYear('created_at', date('Y'))
                ->whereMonth('created_at', date('m'))
                ->whereIn('status', ['completed', 'running', 'queued'])
                ->count();

            if ($usedThisMonth >= $auth->agency->monthly_audit_allowance) {
                http_response_code(403);
                json_response(['error' => 'Monthly audit allowance reached. Upgrade your plan or wait until next month.']);
                return;
            }
        }

        try {
            // Get current methodology version
            $methodVersion = DB::table('meridian_methodology_versions')
                ->where('is_current', true)
                ->first();

            if (!$methodVersion) {
                http_response_code(500);
                json_response(['error' => 'No active methodology version found.']);
                return;
            }

            // Calculate probe count based on audit type and platforms
            $probeCount = $this->calculateProbeCount($auditType, $platforms);

            $auditId = DB::table('meridian_audits')->insertGetId([
                'agency_id'              => $auth->agency_id,
                'client_id'              => $brand->client_id,
                'brand_id'               => $brandId,
                'methodology_version_id' => $methodVersion->id,
                'audit_type'             => $auditType,
                'status'                 => 'queued',
                'initiated_by_user_id'   => $auth->user_id,
                'initiated_by'           => 'manual',
                'platforms'              => json_encode($platforms),
                'probes_total'           => $probeCount,
                'probes_completed'       => 0,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // Create probe run records (queued, to be executed by probe engine)
            $this->createProbeRunRecords(
                $auditId, $auth->agency_id, $brandId,
                (int)$methodVersion->id, $auditType, $platforms
            );

            // Record meter event
            DB::table('meridian_meter_events')->insert([
                'agency_id'      => $auth->agency_id,
                'probe_run_id'   => 0, // Will be updated per run
                'audit_id'       => $auditId,
                'brand_id'       => $brandId,
                'instrument'     => $auditType,
                'platform'       => 'all',
                'units_consumed' => $probeCount,
                'plan_type'      => $auth->agency->plan_type,
                'billable'       => $auth->agency->plan_type === 'enterprise_metered',
                'recorded_at'    => now(),
            ]);

            DB::table('meridian_audit_log')->insert([
                'agency_id'   => $auth->agency_id,
                'user_id'     => $auth->user_id,
                'action'      => 'audit.initiated',
                'entity_type' => 'audit',
                'entity_id'   => $auditId,
                'metadata'    => json_encode([
                    'brand_id'   => $brandId,
                    'brand_name' => $brand->name,
                    'audit_type' => $auditType,
                    'platforms'  => $platforms,
                ]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);

            json_response([
                'status'   => 'ok',
                'auditId'  => $auditId,
                'message'  => 'Audit queued. Probes will begin momentarily.',
                'probeCount' => $probeCount,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian] audit.initiate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/audit/status?id=X ─────────────────────
    public function status(): void
    {
        $auth    = MeridianAuth::require('viewer');
        $auditId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$auditId) {
            http_response_code(422);
            json_response(['error' => 'Audit ID is required.']);
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
            ->orderBy('created_at')
            ->get(['id', 'instrument', 'platform', 'probe_mode', 'status',
                   'turns_completed', 'dit_turn', 'handoff_turn',
                   't4_winner', 'termination_type', 'error_message',
                   'started_at', 'completed_at']);

        $pct = $audit->probes_total > 0
            ? round(($audit->probes_completed / $audit->probes_total) * 100)
            : 0;

        json_response([
            'status'   => 'ok',
            'audit'    => [
                'id'               => (int)$audit->id,
                'status'           => $audit->status,
                'auditType'        => $audit->audit_type,
                'probesTotal'      => (int)$audit->probes_total,
                'probesCompleted'  => (int)$audit->probes_completed,
                'percentComplete'  => $pct,
                'startedAt'        => $audit->started_at,
                'completedAt'      => $audit->completed_at,
                'errorMessage'     => $audit->error_message,
            ],
            'probeRuns' => $probeRuns->map(fn($r) => [
                'id'              => (int)$r->id,
                'instrument'      => $r->instrument,
                'platform'        => $r->platform,
                'probeMode'       => $r->probe_mode,
                'status'          => $r->status,
                'turnsCompleted'  => (int)$r->turns_completed,
                'ditTurn'         => $r->dit_turn,
                'handoffTurn'     => $r->handoff_turn,
                't4Winner'        => $r->t4_winner,
                'terminationType' => $r->termination_type,
                'errorMessage'    => $r->error_message,
                'startedAt'       => $r->started_at,
                'completedAt'     => $r->completed_at,
            ])->values(),
        ]);
    }

    // ── GET /api/meridian/audit/history?brand_id=X ───────────────
    public function history(): void
    {
        $auth    = MeridianAuth::require('viewer');
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;

        if (!$brandId) {
            http_response_code(422);
            json_response(['error' => 'Brand ID is required.']);
            return;
        }

        // Verify brand belongs to agency
        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found.']);
            return;
        }

        $audits = DB::table('meridian_audits')
            ->where('brand_id', $brandId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Get RCS for each audit for trend data
        $result = $audits->map(function ($a) {
            $rcs = DB::table('meridian_rcs_scores')
                ->where('audit_id', $a->id)
                ->first(['rcs_total', 'ad_readiness_verdict', 'rcs_movement']);

            return [
                'id'              => (int)$a->id,
                'auditType'       => $a->audit_type,
                'status'          => $a->status,
                'initiatedBy'     => $a->initiated_by,
                'probesTotal'     => (int)$a->probes_total,
                'probesCompleted' => (int)$a->probes_completed,
                'rcsTotal'        => $rcs ? (float)$rcs->rcs_total : null,
                'adVerdict'       => $rcs ? $rcs->ad_readiness_verdict : null,
                'rcsMovement'     => $rcs && $rcs->rcs_movement ? (float)$rcs->rcs_movement : null,
                'startedAt'       => $a->started_at,
                'completedAt'     => $a->completed_at,
            ];
        });

        json_response(['status' => 'ok', 'history' => $result->values()]);
    }

    // ── POST /api/meridian/audits/complete ───────────────────────
    // Called by the probe engine when all probe runs are done.
    // Computes and stores RCS, RAR, and ad readiness verdict.
    public function complete(): void
    {
        // Internal endpoint — validated by shared secret, not user session
        $secret = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
        if ($secret !== env('MERIDIAN_INTERNAL_SECRET', '')) {
            http_response_code(401);
            json_response(['error' => 'Unauthorised.']);
            return;
        }

        $body    = request_body();
        $auditId = (int)($body['audit_id'] ?? 0);

        if (!$auditId) {
            http_response_code(422);
            json_response(['error' => 'Audit ID is required.']);
            return;
        }

        $audit = DB::table('meridian_audits')->find($auditId);
        if (!$audit) {
            http_response_code(404);
            json_response(['error' => 'Audit not found.']);
            return;
        }

        try {
            // Compute RCS from journey probe results
            $rcs = $this->computeRcs($auditId, (int)$audit->brand_id);

            // Compute RAR from brand annual sales
            $brand = DB::table('meridian_brands')->find($audit->brand_id);
            $rar   = null;
            if ($brand && $brand->annual_sales) {
                $rar = $this->computeRar($auditId, $brand, $rcs);
            }

            // Get methodology version
            $methodVersion = DB::table('meridian_methodology_versions')->find($audit->methodology_version_id);

            // Store RCS score
            $adVerdict = $this->determineAdVerdict($rcs);

            DB::table('meridian_rcs_scores')->insert([
                'audit_id'               => $auditId,
                'agency_id'              => $audit->agency_id,
                'brand_id'               => $audit->brand_id,
                'methodology_version_id' => $audit->methodology_version_id,
                'generic_presence_score' => $rcs['generic_presence'],
                'dit_timing_score'       => $rcs['dit_timing'],
                'handoff_capture_score'  => $rcs['handoff_capture'],
                'mechanism_severity_score' => $rcs['mechanism_severity'],
                'rcs_total'              => $rcs['total'],
                'previous_rcs'           => $rcs['previous_rcs'],
                'rcs_movement'           => $rcs['movement'],
                'ad_readiness_verdict'   => $adVerdict,
                'computed_at'            => now(),
                'created_at'             => now(),
            ]);

            // Update brand cached scores
            DB::table('meridian_brands')
                ->where('id', $audit->brand_id)
                ->update([
                    'current_rcs'          => (int)round($rcs['total']),
                    'current_ad_verdict'   => $adVerdict,
                    'current_rar'          => $rar ? $rar['rar_today'] : null,
                    'last_audited_at'      => now(),
                    'next_reaudit_at'      => date('Y-m-d', strtotime('+' . ($brand->reaudit_cadence_days ?? 90) . ' days')),
                    'updated_at'           => now(),
                ]);

            // Update DIT cached values on brand
            $this->updateBrandDitCache((int)$audit->brand_id, $auditId);

            // Mark audit complete
            DB::table('meridian_audits')
                ->where('id', $auditId)
                ->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'updated_at'   => now(),
                ]);

            // Activate re-audit schedule
            DB::table('meridian_reaudit_schedules')
                ->where('brand_id', $audit->brand_id)
                ->update([
                    'is_active'   => true,
                    'last_run_at' => now(),
                    'next_run_at' => date('Y-m-d H:i:s', strtotime('+' . ($brand->reaudit_cadence_days ?? 90) . ' days')),
                    'updated_at'  => now(),
                ]);

            // Send completion notification
            $this->sendCompletionNotification($audit, $rcs, $adVerdict);

            json_response(['status' => 'ok', 'rcs' => $rcs, 'adVerdict' => $adVerdict]);

        } catch (\Throwable $e) {
            log_error('[Meridian] audit.complete error', ['error' => $e->getMessage(), 'audit_id' => $auditId]);

            DB::table('meridian_audits')
                ->where('id', $auditId)
                ->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'updated_at' => now()]);

            http_response_code(500);
            json_response(['error' => $e->getMessage()]);
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function calculateProbeCount(string $auditType, array $platforms): int
    {
        $platformCount = count($platforms);
        return match($auditType) {
            'full'    => ($platformCount * 2) + ($platformCount * 1),  // Journey (anchored + generic) + DPA
            'journey' => $platformCount * 2,  // Anchored + generic per platform
            'dpa'     => $platformCount,
            'psos'    => 1,
            'citation'=> $platformCount,
            default   => $platformCount * 2,
        };
    }

    private function createProbeRunRecords(
        int $auditId, int $agencyId, int $brandId,
        int $methodVersionId, string $auditType, array $platforms
    ): void {
        $runs = [];
        $now  = now();

        foreach ($platforms as $platform) {
            if (in_array($auditType, ['full', 'journey'])) {
                // Anchored journey probe
                $runs[] = [
                    'audit_id'               => $auditId,
                    'agency_id'              => $agencyId,
                    'brand_id'               => $brandId,
                    'methodology_version_id' => $methodVersionId,
                    'instrument'             => 'journey_anchored',
                    'platform'               => $platform,
                    'probe_mode'             => 'anchored',
                    'status'                 => 'queued',
                    'turns_completed'        => 0,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];
                // Generic journey probe
                $runs[] = [
                    'audit_id'               => $auditId,
                    'agency_id'              => $agencyId,
                    'brand_id'               => $brandId,
                    'methodology_version_id' => $methodVersionId,
                    'instrument'             => 'journey_generic',
                    'platform'               => $platform,
                    'probe_mode'             => 'generic',
                    'status'                 => 'queued',
                    'turns_completed'        => 0,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];
            }

            if (in_array($auditType, ['full', 'dpa'])) {
                $runs[] = [
                    'audit_id'               => $auditId,
                    'agency_id'              => $agencyId,
                    'brand_id'               => $brandId,
                    'methodology_version_id' => $methodVersionId,
                    'instrument'             => 'dpa',
                    'platform'               => $platform,
                    'probe_mode'             => 'directed',
                    'status'                 => 'queued',
                    'turns_completed'        => 0,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];
            }
        }

        if (in_array($auditType, ['full', 'psos'])) {
            $runs[] = [
                'audit_id'               => $auditId,
                'agency_id'              => $agencyId,
                'brand_id'               => $brandId,
                'methodology_version_id' => $methodVersionId,
                'instrument'             => 'psos',
                'platform'               => 'all',
                'probe_mode'             => null,
                'status'                 => 'queued',
                'turns_completed'        => 0,
                'created_at'             => $now,
                'updated_at'             => $now,
            ];
        }

        if ($runs) {
            DB::table('meridian_probe_runs')->insert($runs);
        }
    }

    private function computeRcs(int $auditId, int $brandId): array
    {
        // Generic presence score (0-100): was brand present in ANY generic probe?
        $genericRuns = DB::table('meridian_journey_probe_runs')
            ->where('audit_id', $auditId)
            ->where('probe_mode', 'generic')
            ->get();

        $genericPresent = $genericRuns->where('generic_probe_result', 'present')->count();
        $genericTotal   = $genericRuns->count();
        $genericScore   = $genericTotal > 0
            ? ($genericPresent / $genericTotal) * 100
            : 0;

        // DIT timing score (0-100): later DIT = higher score; null DIT (never displaced) = 100
        $anchoredRuns = DB::table('meridian_journey_probe_runs')
            ->where('audit_id', $auditId)
            ->where('probe_mode', 'anchored')
            ->get();

        $ditScores = $anchoredRuns->map(function ($r) {
            if ($r->dit_turn === null) return 100;      // Never displaced
            $maxTurns = max((int)$r->total_turns, 12);
            return min(100, (((int)$r->dit_turn - 1) / ($maxTurns - 1)) * 100);
        });

        $ditScore = $ditScores->count() > 0 ? $ditScores->avg() : 0;

        // Handoff capture score (0-100): was brand primary at handoff?
        $handoffEvents = DB::table('meridian_handoff_events')
            ->where('audit_id', $auditId)
            ->get();

        $handoffCaptured = $handoffEvents->where('brand_present_at_handoff', true)
            ->where('brand_presence_at_handoff', 'primary')->count();
        $handoffTotal = $handoffEvents->count();
        $handoffScore = $handoffTotal > 0
            ? ($handoffCaptured / $handoffTotal) * 100
            : 0;

        // Mechanism severity score (0-100): inverse of displacement severity
        // CAPTURE/SUBSTITUTION = worst (0), EQUALISATION/COMPARATIVE = medium (50), null DIT = best (100)
        $severityMap = [
            'capture'      => 0,
            'substitution' => 10,
            'evaluative'   => 25,
            'price'        => 20,
            'comparative'  => 40,
            'equalisation' => 50,
            'educational'  => 60,
            'deflecting'   => 70,
            'conversion_loop' => 75,
        ];

        $mechanismScores = $anchoredRuns->map(function ($r) use ($severityMap) {
            if ($r->dit_turn === null) return 100;
            return $severityMap[$r->dit_type ?? 'comparative'] ?? 50;
        });

        $mechanismScore = $mechanismScores->count() > 0 ? $mechanismScores->avg() : 50;

        // Weighted composite RCS
        $total = (
            ($genericScore   * 0.30) +
            ($ditScore       * 0.25) +
            ($handoffScore   * 0.30) +
            ($mechanismScore * 0.15)
        );

        // Get previous RCS for movement tracking
        $previousRcs = DB::table('meridian_rcs_scores')
            ->where('brand_id', $brandId)
            ->orderByDesc('computed_at')
            ->value('rcs_total');

        return [
            'generic_presence' => round($genericScore, 2),
            'dit_timing'       => round($ditScore, 2),
            'handoff_capture'  => round($handoffScore, 2),
            'mechanism_severity' => round($mechanismScore, 2),
            'total'            => round($total, 2),
            'previous_rcs'     => $previousRcs ? (float)$previousRcs : null,
            'movement'         => $previousRcs ? round($total - (float)$previousRcs, 2) : null,
        ];
    }

    private function computeRar(int $auditId, object $brand, array $rcs): ?array
    {
        $annualSales   = (float)$brand->annual_sales;
        $discoveryShare = 0.40;
        $visibilityGap  = 1 - ($rcs['generic_presence'] / 100);
        $conservatism   = 0.40;

        $rarToday = $annualSales * $discoveryShare * $visibilityGap * 0.15 * $conservatism;
        $rar6mo   = $annualSales * $discoveryShare * $visibilityGap * 0.25 * $conservatism;
        $rar12mo  = $annualSales * $discoveryShare * $visibilityGap * 0.30 * $conservatism;
        $rarFull  = $annualSales * $discoveryShare * $visibilityGap * 1.00 * $conservatism;

        DB::table('meridian_rar_calculations')->insert([
            'audit_id'             => $auditId,
            'agency_id'            => $brand->agency_id,
            'brand_id'             => $brand->id,
            'annual_sales'         => $annualSales,
            'annual_sales_currency'=> $brand->annual_sales_currency ?? 'GBP',
            'discovery_share'      => $discoveryShare,
            'visibility_gap'       => round($visibilityGap, 3),
            'conservatism_factor'  => $conservatism,
            'rar_llm_share_today'  => round($rarToday, 2),
            'rar_llm_share_6mo'    => round($rar6mo,   2),
            'rar_llm_share_12mo'   => round($rar12mo,  2),
            'rar_llm_share_full'   => round($rarFull,  2),
            'llm_share_today'      => 0.15,
            'llm_share_6mo'        => 0.25,
            'llm_share_12mo'       => 0.30,
            'computed_at'          => now(),
            'created_at'           => now(),
        ]);

        return ['rar_today' => round($rarToday, 2)];
    }

    private function determineAdVerdict(array $rcs): string
    {
        if ($rcs['total'] >= 70 && $rcs['generic_presence'] >= 50) {
            return 'amplification_ready';
        }
        if ($rcs['total'] >= 40) {
            return 'monitor';
        }
        return 'do_not_advertise';
    }

    private function updateBrandDitCache(int $brandId, int $auditId): void
    {
        $platforms = ['chatgpt', 'gemini', 'perplexity', 'grok'];
        $updates   = [];

        foreach ($platforms as $platform) {
            $run = DB::table('meridian_journey_probe_runs')
                ->where('audit_id', $auditId)
                ->where('platform', $platform)
                ->where('probe_mode', 'anchored')
                ->first();

            $updates['current_dit_' . $platform] = $run ? $run->dit_turn : null;
        }

        $updates['updated_at'] = now();
        DB::table('meridian_brands')->where('id', $brandId)->update($updates);
    }

    private function sendCompletionNotification(object $audit, array $rcs, string $verdict): void
    {
        // Get agency admin users to notify
        $admins = DB::table('meridian_agency_users')
            ->where('agency_id', $audit->agency_id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get(['id', 'email', 'first_name']);

        $brand = DB::table('meridian_brands')->find($audit->brand_id);

        foreach ($admins as $admin) {
            DB::table('meridian_notifications')->insert([
                'agency_id'         => $audit->agency_id,
                'user_id'           => $admin->id,
                'notification_type' => 'audit_complete',
                'severity'          => 'info',
                'title'             => 'Audit complete — ' . ($brand->name ?? 'Brand'),
                'body'              => sprintf(
                    'RCS: %d. Verdict: %s. %s',
                    (int)$rcs['total'],
                    str_replace('_', ' ', $verdict),
                    $rcs['movement'] !== null
                        ? ($rcs['movement'] >= 0 ? '↑' : '↓') . ' ' . abs($rcs['movement']) . ' pts vs last audit'
                        : 'First audit for this brand.'
                ),
                'brand_id'          => $audit->brand_id,
                'audit_id'          => $audit->id,
                'email_sent'        => false,
                'in_app_read'       => false,
                'created_at'        => now(),
            ]);
        }
    }
}
