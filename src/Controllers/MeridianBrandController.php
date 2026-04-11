<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianBrandController
 *
 * GET  /api/meridian/brands           — List brands (filter by client_id or brand_type)
 * GET  /api/meridian/brand            — Single brand detail with latest audit data (?id=X)
 * POST /api/meridian/brands/create    — Create brand
 * POST /api/meridian/brands/update    — Update brand (id in body)
 * POST /api/meridian/brands/delete    — Delete brand (id in body)
 * POST /api/meridian/brands/prompts   — Save probe prompts for brand
 */
class MeridianBrandController
{
    // ── GET /api/meridian/brands ─────────────────────────────────
    public function list(): void
    {
        $auth     = MeridianAuth::require('viewer');
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        $type     = $_GET['type'] ?? 'monitored'; // 'monitored' | 'competitor' | 'all'

        try {
            $query = DB::table('meridian_brands')
                ->where('agency_id', $auth->agency_id)
                ->where('is_active', true)
                ->whereNull('deleted_at');

            if ($clientId) {
                $query->where('client_id', $clientId);
            }

            if ($type !== 'all') {
                $query->where('brand_type', $type);
            }

            $brands = $query->orderBy('name')->get();

            $result = $brands->map(fn($b) => $this->formatBrand($b));

            json_response(['status' => 'ok', 'brands' => $result]);

        } catch (\Throwable $e) {
            log_error('[Meridian] brands.list error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/brand?id=X ────────────────────────────
    // Returns full brand detail including latest audit results
    public function detail(): void
    {
        $auth = MeridianAuth::require('viewer');
        $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$id) {
            http_response_code(422);
            json_response(['error' => 'Brand ID is required.']);
            return;
        }

        try {
            $brand = DB::table('meridian_brands')
                ->where('id', $id)
                ->where('agency_id', $auth->agency_id)
                ->whereNull('deleted_at')
                ->first();

            if (!$brand) {
                http_response_code(404);
                json_response(['error' => 'Brand not found.']);
                return;
            }

            // Get latest completed audit
            $latestAudit = DB::table('meridian_audits')
                ->where('brand_id', $id)
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->first();

            $auditData = null;
            if ($latestAudit) {
                $auditData = $this->getAuditDetail((int)$latestAudit->id);
            }

            // Get audit history (last 10)
            $auditHistory = DB::table('meridian_audits')
                ->where('brand_id', $id)
                ->whereIn('status', ['completed', 'failed'])
                ->orderByDesc('completed_at')
                ->limit(10)
                ->get(['id', 'audit_type', 'status', 'initiated_by',
                       'started_at', 'completed_at', 'probes_total', 'probes_completed']);

            // Get active remediation plan
            $remediationPlan = null;
            if ($latestAudit) {
                $plan = DB::table('meridian_remediation_plans')
                    ->where('brand_id', $id)
                    ->whereIn('status', ['active', 'draft'])
                    ->orderByDesc('created_at')
                    ->first();

                if ($plan) {
                    $items = DB::table('meridian_remediation_items')
                        ->where('plan_id', $plan->id)
                        ->orderBy('priority_rank')
                        ->get();

                    $remediationPlan = [
                        'id'               => (int)$plan->id,
                        'status'           => $plan->status,
                        'totalItems'       => (int)$plan->total_items,
                        'itemsCompleted'   => (int)$plan->items_completed,
                        'completionRate'   => (float)$plan->completion_rate,
                        'briefText'        => $plan->brief_text,
                        'items'            => $items->map(fn($i) => [
                            'id'                 => (int)$i->id,
                            'priorityRank'       => (int)$i->priority_rank,
                            'citationTier'       => $i->citation_tier,
                            'actionDescription'  => $i->action_description,
                            'targetSources'      => json_decode($i->target_sources ?? '[]', true),
                            'expectedTimeline'   => $i->expected_timeline_weeks,
                            'status'             => $i->status,
                            'targetDate'         => $i->target_date,
                            'platformSpecific'   => $i->platform_specific,
                        ])->values(),
                    ];
                }
            }

            // Get configured prompts
            $prompts = DB::table('meridian_brand_prompts')
                ->where('brand_id', $id)
                ->where('is_active', true)
                ->get(['prompt_type', 'prompt_text']);

            $promptMap = [];
            foreach ($prompts as $p) {
                $promptMap[$p->prompt_type] = $p->prompt_text;
            }

            json_response([
                'status'          => 'ok',
                'brand'           => $this->formatBrand($brand),
                'latestAudit'     => $auditData,
                'auditHistory'    => $auditHistory,
                'remediationPlan' => $remediationPlan,
                'prompts'         => $promptMap,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian] brand.detail error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/brands/create ─────────────────────────
    public function create(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();

        $name     = trim($body['name']      ?? '');
        $clientId = (int)($body['client_id'] ?? 0);

        if (!$name || !$clientId) {
            http_response_code(422);
            json_response(['error' => 'Brand name and client ID are required.']);
            return;
        }

        // Verify client belongs to this agency
        $client = DB::table('meridian_clients')
            ->where('id', $clientId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$client) {
            http_response_code(404);
            json_response(['error' => 'Client not found.']);
            return;
        }

        // Check plan brand limit
        if ($auth->agency->max_brands !== null) {
            $current = DB::table('meridian_brands')
                ->where('agency_id', $auth->agency_id)
                ->where('brand_type', 'monitored')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count();

            if ($current >= $auth->agency->max_brands) {
                http_response_code(403);
                json_response(['error' => 'Brand limit reached for your plan. Upgrade to add more brands.']);
                return;
            }
        }

        try {
            $brandType = trim($body['brand_type'] ?? 'monitored');
            if (!in_array($brandType, ['monitored', 'competitor'])) {
                $brandType = 'monitored';
            }

            $cadence = (int)($body['reaudit_cadence_days'] ?? 90);
            if (!in_array($cadence, [30, 60, 90])) $cadence = 90;

            $id = DB::table('meridian_brands')->insertGetId([
                'agency_id'              => $auth->agency_id,
                'client_id'              => $clientId,
                'name'                   => $name,
                'category'               => trim($body['category']    ?? ''),
                'subcategory'            => trim($body['subcategory']  ?? ''),
                'brand_type'             => $brandType,
                'annual_sales'           => $body['annual_sales']      ?? null,
                'annual_sales_currency'  => trim($body['currency']     ?? 'GBP'),
                'market'                 => trim($body['market']       ?? 'UK'),
                'website'                => trim($body['website']      ?? ''),
                'reaudit_cadence_days'   => $cadence,
                'is_active'              => true,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // Save prompts if provided
            if (!empty($body['prompts']) && is_array($body['prompts'])) {
                $this->savePrompts($id, $auth->agency_id, $body['prompts']);
            }

            // Create re-audit schedule
            DB::table('meridian_reaudit_schedules')->insert([
                'agency_id'   => $auth->agency_id,
                'brand_id'    => $id,
                'cadence_days'=> $cadence,
                'next_run_at' => date('Y-m-d H:i:s', strtotime("+{$cadence} days")),
                'audit_type'  => 'full',
                'is_active'   => false, // Activated when first audit completes
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('meridian_audit_log')->insert([
                'agency_id'   => $auth->agency_id,
                'user_id'     => $auth->user_id,
                'action'      => 'brand.created',
                'entity_type' => 'brand',
                'entity_id'   => $id,
                'metadata'    => json_encode(['name' => $name, 'client_id' => $clientId]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);

            $brand = DB::table('meridian_brands')->find($id);
            json_response(['status' => 'ok', 'brand' => $this->formatBrand($brand)]);

        } catch (\Throwable $e) {
            log_error('[Meridian] brands.create error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/brands/update ─────────────────────────
    public function update(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();
        $id   = (int)($body['id'] ?? 0);

        if (!$id) {
            http_response_code(422);
            json_response(['error' => 'Brand ID is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $id)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found.']);
            return;
        }

        try {
            $updates = ['updated_at' => now()];

            $fields = ['name', 'category', 'subcategory', 'market', 'website'];
            foreach ($fields as $f) {
                if (isset($body[$f])) $updates[$f] = trim($body[$f]);
            }

            if (isset($body['annual_sales']))         $updates['annual_sales']           = $body['annual_sales'];
            if (isset($body['currency']))              $updates['annual_sales_currency']  = trim($body['currency']);
            if (isset($body['reaudit_cadence_days'])) {
                $cadence = (int)$body['reaudit_cadence_days'];
                if (in_array($cadence, [30, 60, 90])) {
                    $updates['reaudit_cadence_days'] = $cadence;
                }
            }

            DB::table('meridian_brands')->where('id', $id)->update($updates);

            // Update prompts if provided
            if (!empty($body['prompts']) && is_array($body['prompts'])) {
                $this->savePrompts($id, $auth->agency_id, $body['prompts']);
            }

            $updated = DB::table('meridian_brands')->find($id);
            json_response(['status' => 'ok', 'brand' => $this->formatBrand($updated)]);

        } catch (\Throwable $e) {
            log_error('[Meridian] brands.update error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/brands/delete ─────────────────────────
    public function delete(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();
        $id   = (int)($body['id'] ?? 0);

        if (!$id) {
            http_response_code(422);
            json_response(['error' => 'Brand ID is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $id)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found.']);
            return;
        }

        try {
            DB::table('meridian_brands')
                ->where('id', $id)
                ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);

            DB::table('meridian_reaudit_schedules')
                ->where('brand_id', $id)
                ->update(['is_active' => false, 'updated_at' => now()]);

            DB::table('meridian_audit_log')->insert([
                'agency_id'   => $auth->agency_id,
                'user_id'     => $auth->user_id,
                'action'      => 'brand.deleted',
                'entity_type' => 'brand',
                'entity_id'   => $id,
                'metadata'    => json_encode(['name' => $brand->name]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[Meridian] brands.delete error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/brands/prompts ────────────────────────
    public function savePromptsEndpoint(): void
    {
        $auth    = MeridianAuth::require('analyst');
        $body    = request_body();
        $brandId = (int)($body['brand_id'] ?? 0);

        if (!$brandId || empty($body['prompts'])) {
            http_response_code(422);
            json_response(['error' => 'Brand ID and prompts are required.']);
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

        try {
            $this->savePrompts($brandId, $auth->agency_id, $body['prompts']);
            json_response(['status' => 'ok']);
        } catch (\Throwable $e) {
            log_error('[Meridian] brands.prompts error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function formatBrand(object $b): array
    {
        return [
            'id'                  => (int)$b->id,
            'clientId'            => (int)$b->client_id,
            'name'                => $b->name,
            'category'            => $b->category,
            'subcategory'         => $b->subcategory,
            'brandType'           => $b->brand_type,
            'annualSales'         => $b->annual_sales ? (float)$b->annual_sales : null,
            'annualSalesCurrency' => $b->annual_sales_currency,
            'market'              => $b->market,
            'website'             => $b->website,
            'reauditCadenceDays'  => (int)$b->reaudit_cadence_days,
            'nextReauditAt'       => $b->next_reaudit_at,
            'lastAuditedAt'       => $b->last_audited_at,
            // Cached scores for dashboard display
            'currentRcs'          => $b->current_rcs !== null ? (int)$b->current_rcs : null,
            'currentDitChatgpt'   => $b->current_dit_chatgpt !== null ? (int)$b->current_dit_chatgpt : null,
            'currentDitGemini'    => $b->current_dit_gemini  !== null ? (int)$b->current_dit_gemini  : null,
            'currentDitPerplexity'=> $b->current_dit_perplexity !== null ? (int)$b->current_dit_perplexity : null,
            'currentDitGrok'      => $b->current_dit_grok   !== null ? (int)$b->current_dit_grok    : null,
            'currentRar'          => $b->current_rar ? (float)$b->current_rar : null,
            'adVerdict'           => $b->current_ad_verdict,
            'createdAt'           => $b->created_at,
            'updatedAt'           => $b->updated_at,
        ];
    }

    private function getAuditDetail(int $auditId): array
    {
        $audit = DB::table('meridian_audits')->find($auditId);

        // RCS score
        $rcs = DB::table('meridian_rcs_scores')
            ->where('audit_id', $auditId)
            ->first();

        // RAR calculation
        $rar = DB::table('meridian_rar_calculations')
            ->where('audit_id', $auditId)
            ->first();

        // Journey probe runs
        $journeyRuns = DB::table('meridian_journey_probe_runs')
            ->where('audit_id', $auditId)
            ->get();

        // DPA runs
        $dpaRuns = DB::table('meridian_dpa_runs')
            ->where('audit_id', $auditId)
            ->get();

        // Ad readiness verdicts
        $adVerdicts = DB::table('meridian_ad_readiness_verdicts')
            ->where('audit_id', $auditId)
            ->get();

        return [
            'auditId'     => $auditId,
            'completedAt' => $audit->completed_at,
            'auditType'   => $audit->audit_type,
            'platforms'   => json_decode($audit->platforms ?? '[]', true),
            'rcs'         => $rcs ? [
                'total'                 => (float)$rcs->rcs_total,
                'genericPresence'       => (float)$rcs->generic_presence_score,
                'ditTiming'             => (float)$rcs->dit_timing_score,
                'handoffCapture'        => (float)$rcs->handoff_capture_score,
                'mechanismSeverity'     => (float)$rcs->mechanism_severity_score,
                'previousRcs'           => $rcs->previous_rcs ? (float)$rcs->previous_rcs : null,
                'movement'              => $rcs->rcs_movement ? (float)$rcs->rcs_movement : null,
                'adVerdict'             => $rcs->ad_readiness_verdict,
            ] : null,
            'rar' => $rar ? [
                'annualSales'       => (float)$rar->annual_sales,
                'currency'          => $rar->annual_sales_currency,
                'rarToday'          => $rar->rar_llm_share_today ? (float)$rar->rar_llm_share_today : null,
                'rar6mo'            => $rar->rar_llm_share_6mo   ? (float)$rar->rar_llm_share_6mo   : null,
                'rar12mo'           => $rar->rar_llm_share_12mo  ? (float)$rar->rar_llm_share_12mo  : null,
                'discoveryShare'    => (float)$rar->discovery_share,
                'visibilityGap'     => (float)$rar->visibility_gap,
                'llmShareToday'     => (float)$rar->llm_share_today,
            ] : null,
            'journeyRuns' => $journeyRuns->map(fn($r) => [
                'platform'        => $r->platform,
                'probeMode'       => $r->probe_mode,
                'totalTurns'      => $r->total_turns,
                'ditTurn'         => $r->dit_turn,
                'ditType'         => $r->dit_type,
                'displacingBrand' => $r->displacing_brand,
                'handoffTurn'     => $r->handoff_turn,
                'brandAtHandoff'  => $r->brand_at_handoff,
                'terminationType' => $r->termination_type,
                'genericResult'   => $r->generic_probe_result,
                'considerationSet'=> json_decode($r->consideration_set ?? '[]', true),
                'primaryTurnRate' => $r->primary_turn_rate ? (float)$r->primary_turn_rate : null,
            ])->values(),
            'dpaRuns' => $dpaRuns->map(fn($r) => [
                'platform'     => $r->platform,
                'totalScore'   => $r->total_score,
                'band'         => $r->band,
                'filterType'   => $r->filter_type,
                't4Outcome'    => $r->t4_outcome,
                't4Winner'     => $r->t4_winner,
            ])->values(),
            'adVerdicts' => $adVerdicts->map(fn($v) => [
                'platform'             => $v->platform,
                'verdict'              => $v->verdict,
                'verdictRationale'     => $v->verdict_rationale,
                'q1ReasoningChain'     => $v->q1_reasoning_chain_position,
                'q1Detail'             => $v->q1_detail,
                'q2HandoffCapture'     => $v->q2_handoff_capture,
                'q2Detail'             => $v->q2_detail,
                'q3GenericConsideration' => $v->q3_generic_consideration,
                'q3Detail'             => $v->q3_detail,
                'q4Displacement'       => $v->q4_displacement_mechanism,
                'q4Detail'             => $v->q4_detail,
                'q5Rar'                => $v->q5_revenue_at_risk,
                'q5Detail'             => $v->q5_detail,
                'rcsGapToAmplification'=> $v->rcs_gap_to_amplification,
                'recommendedIntervention' => $v->recommended_intervention,
            ])->values(),
        ];
    }

    private function savePrompts(int $brandId, int $agencyId, array $prompts): void
    {
        $validTypes = [
            'anchored_t1', 'generic_t1',
            'dpa_t1', 'dpa_t2', 'dpa_t3', 'dpa_t4',
        ];

        foreach ($prompts as $type => $text) {
            if (!in_array($type, $validTypes)) continue;
            $text = trim($text);
            if (!$text) continue;

            $existing = DB::table('meridian_brand_prompts')
                ->where('brand_id', $brandId)
                ->where('prompt_type', $type)
                ->where('is_active', true)
                ->first();

            if ($existing) {
                DB::table('meridian_brand_prompts')
                    ->where('id', $existing->id)
                    ->update(['prompt_text' => $text, 'version' => $existing->version + 1, 'updated_at' => now()]);
            } else {
                DB::table('meridian_brand_prompts')->insert([
                    'brand_id'    => $brandId,
                    'agency_id'   => $agencyId,
                    'prompt_type' => $type,
                    'prompt_text' => $text,
                    'is_active'   => true,
                    'version'     => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }
}
