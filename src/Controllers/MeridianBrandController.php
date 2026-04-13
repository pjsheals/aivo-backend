<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianBrandController
{
    public function list(): void
    {
        $auth     = MeridianAuth::require('viewer');
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        $type     = $_GET['type'] ?? 'monitored';

        try {
            $query = DB::table('meridian_brands')
                ->where('agency_id', $auth->agency_id)
                ->where('is_active', true)
                ->whereNull('deleted_at');

            if ($clientId) $query->where('client_id', $clientId);
            if ($type !== 'all') $query->where('brand_type', $type);

            $brands = $query->orderBy('name')->get();
            json_response(['status' => 'ok', 'brands' => $brands->map(fn($b) => $this->formatBrand($b))]);
        } catch (\Throwable $e) {
            log_error('[Meridian] brands.list error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    public function detail(): void
    {
        $auth = MeridianAuth::require('viewer');
        $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$id) { http_response_code(422); json_response(['error' => 'Brand ID is required.']); return; }

        try {
            $brand = DB::table('meridian_brands')
                ->where('id', $id)->where('agency_id', $auth->agency_id)->whereNull('deleted_at')->first();

            if (!$brand) { http_response_code(404); json_response(['error' => 'Brand not found.']); return; }

            $latestAudit = DB::table('meridian_audits')
                ->where('brand_id', $id)->where('status', 'completed')
                ->orderByDesc('completed_at')->first();

            $auditData = null;
            if ($latestAudit) $auditData = $this->getAuditDetail((int)$latestAudit->id, $brand);

            $auditHistory = DB::table('meridian_audits')
                ->where('brand_id', $id)->whereIn('status', ['completed', 'failed'])
                ->orderByDesc('completed_at')->limit(10)
                ->get(['id', 'audit_type', 'status', 'initiated_by', 'started_at', 'completed_at', 'probes_total', 'probes_completed']);

            $remediationPlan = null;
            if ($latestAudit) {
                $plan = DB::table('meridian_remediation_plans')
                    ->where('brand_id', $id)->whereIn('status', ['active', 'draft'])
                    ->orderByDesc('created_at')->first();
                if ($plan) {
                    $items = DB::table('meridian_remediation_items')
                        ->where('plan_id', $plan->id)->orderBy('priority_rank')->get();
                    $remediationPlan = [
                        'id' => (int)$plan->id, 'status' => $plan->status,
                        'totalItems' => (int)$plan->total_items, 'itemsCompleted' => (int)$plan->items_completed,
                        'completionRate' => (float)$plan->completion_rate, 'briefText' => $plan->brief_text,
                        'items' => $items->map(fn($i) => [
                            'id' => (int)$i->id, 'priorityRank' => (int)$i->priority_rank,
                            'citationTier' => $i->citation_tier, 'actionDescription' => $i->action_description,
                            'targetSources' => json_decode($i->target_sources ?? '[]', true),
                            'expectedTimeline' => $i->expected_timeline_weeks, 'status' => $i->status,
                            'targetDate' => $i->target_date, 'platformSpecific' => $i->platform_specific,
                        ])->values(),
                    ];
                }
            }

            // ── AI Remediation — cached Claude-generated 7-section report ────
            $remediationAI = null;
            $remRow = DB::table('meridian_brand_audit_results')
                ->where('brand_id', $id)
                ->whereNotNull('remediation_json')
                ->orderByDesc('created_at')
                ->first(['remediation_json', 'remediation_generated_at']);
            if ($remRow) {
                $remediationAI = json_decode($remRow->remediation_json, true);
                $remediationAI['_generated_at'] = $remRow->remediation_generated_at;
            }
            // ────────────────────────────────────────────────────────────────

            $prompts = DB::table('meridian_brand_prompts')
                ->where('brand_id', $id)->where('is_active', true)->get(['prompt_type', 'prompt_text']);
            $promptMap = [];
            foreach ($prompts as $p) $promptMap[$p->prompt_type] = $p->prompt_text;

            json_response([
                'status'          => 'ok',
                'brand'           => $this->formatBrand($brand),
                'latestAudit'     => $auditData,
                'auditHistory'    => $auditHistory,
                'remediationPlan' => $remediationPlan,
                'remediationAI'   => $remediationAI,
                'prompts'         => $promptMap,
            ]);
        } catch (\Throwable $e) {
            log_error('[Meridian] brand.detail error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    public function create(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();
        $name = trim($body['name'] ?? '');
        $clientId = (int)($body['client_id'] ?? 0);

        if (!$name || !$clientId) { http_response_code(422); json_response(['error' => 'Brand name and client ID are required.']); return; }

        $client = DB::table('meridian_clients')->where('id', $clientId)->where('agency_id', $auth->agency_id)->whereNull('deleted_at')->first();
        if (!$client) { http_response_code(404); json_response(['error' => 'Client not found.']); return; }

        if ($auth->agency->max_brands !== null) {
            $current = DB::table('meridian_brands')->where('agency_id', $auth->agency_id)->where('brand_type', 'monitored')->where('is_active', true)->whereNull('deleted_at')->count();
            if ($current >= $auth->agency->max_brands) { http_response_code(403); json_response(['error' => 'Brand limit reached for your plan.']); return; }
        }

        try {
            $brandType = trim($body['brand_type'] ?? 'monitored');
            if (!in_array($brandType, ['monitored', 'competitor'])) $brandType = 'monitored';
            $cadence = (int)($body['reaudit_cadence_days'] ?? 90);
            if (!in_array($cadence, [30, 60, 90])) $cadence = 90;

            $id = DB::table('meridian_brands')->insertGetId([
                'agency_id' => $auth->agency_id, 'client_id' => $clientId, 'name' => $name,
                'category' => trim($body['category'] ?? ''), 'subcategory' => trim($body['subcategory'] ?? ''),
                'brand_type' => $brandType, 'annual_sales' => $body['annual_sales'] ?? null,
                'annual_sales_currency' => trim($body['currency'] ?? 'GBP'), 'market' => trim($body['market'] ?? 'UK'),
                'website' => trim($body['website'] ?? ''), 'reaudit_cadence_days' => $cadence,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if (!empty($body['prompts']) && is_array($body['prompts'])) $this->savePrompts($id, $auth->agency_id, $body['prompts']);

            DB::table('meridian_reaudit_schedules')->insert([
                'agency_id' => $auth->agency_id, 'brand_id' => $id, 'cadence_days' => $cadence,
                'next_run_at' => date('Y-m-d H:i:s', strtotime("+{$cadence} days")),
                'audit_type' => 'full', 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('meridian_audit_log')->insert([
                'agency_id' => $auth->agency_id, 'user_id' => $auth->user_id,
                'action' => 'brand.created', 'entity_type' => 'brand', 'entity_id' => $id,
                'metadata' => json_encode(['name' => $name, 'client_id' => $clientId]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null, 'created_at' => now(),
            ]);

            $brand = DB::table('meridian_brands')->find($id);
            json_response(['status' => 'ok', 'brand' => $this->formatBrand($brand)]);
        } catch (\Throwable $e) {
            log_error('[Meridian] brands.create error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    public function update(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { http_response_code(422); json_response(['error' => 'Brand ID is required.']); return; }

        $brand = DB::table('meridian_brands')->where('id', $id)->where('agency_id', $auth->agency_id)->whereNull('deleted_at')->first();
        if (!$brand) { http_response_code(404); json_response(['error' => 'Brand not found.']); return; }

        try {
            $updates = ['updated_at' => now()];
            foreach (['name', 'category', 'subcategory', 'market', 'website'] as $f) {
                if (isset($body[$f])) $updates[$f] = trim($body[$f]);
            }
            if (isset($body['annual_sales'])) $updates['annual_sales'] = $body['annual_sales'];
            if (isset($body['currency'])) $updates['annual_sales_currency'] = trim($body['currency']);
            if (isset($body['reaudit_cadence_days'])) {
                $c = (int)$body['reaudit_cadence_days'];
                if (in_array($c, [30, 60, 90])) $updates['reaudit_cadence_days'] = $c;
            }
            DB::table('meridian_brands')->where('id', $id)->update($updates);
            if (!empty($body['prompts']) && is_array($body['prompts'])) $this->savePrompts($id, $auth->agency_id, $body['prompts']);
            $updated = DB::table('meridian_brands')->find($id);
            json_response(['status' => 'ok', 'brand' => $this->formatBrand($updated)]);
        } catch (\Throwable $e) {
            log_error('[Meridian] brands.update error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    public function delete(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { http_response_code(422); json_response(['error' => 'Brand ID is required.']); return; }

        $brand = DB::table('meridian_brands')->where('id', $id)->where('agency_id', $auth->agency_id)->whereNull('deleted_at')->first();
        if (!$brand) { http_response_code(404); json_response(['error' => 'Brand not found.']); return; }

        try {
            DB::table('meridian_brands')->where('id', $id)->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('meridian_reaudit_schedules')->where('brand_id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('meridian_audit_log')->insert([
                'agency_id' => $auth->agency_id, 'user_id' => $auth->user_id,
                'action' => 'brand.deleted', 'entity_type' => 'brand', 'entity_id' => $id,
                'metadata' => json_encode(['name' => $brand->name]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null, 'created_at' => now(),
            ]);
            json_response(['status' => 'ok']);
        } catch (\Throwable $e) {
            log_error('[Meridian] brands.delete error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    public function savePromptsEndpoint(): void
    {
        $auth    = MeridianAuth::require('analyst');
        $body    = request_body();
        $brandId = (int)($body['brand_id'] ?? 0);
        if (!$brandId || empty($body['prompts'])) { http_response_code(422); json_response(['error' => 'Brand ID and prompts are required.']); return; }

        $brand = DB::table('meridian_brands')->where('id', $brandId)->where('agency_id', $auth->agency_id)->whereNull('deleted_at')->first();
        if (!$brand) { http_response_code(404); json_response(['error' => 'Brand not found.']); return; }

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
            'category'            => $b->category ?? null,
            'subcategory'         => $b->subcategory ?? null,
            'brandType'           => $b->brand_type ?? 'monitored',
            'annualSales'         => isset($b->annual_sales) && $b->annual_sales ? (float)$b->annual_sales : null,
            'annualSalesCurrency' => $b->annual_sales_currency ?? 'GBP',
            'market'              => $b->market ?? null,
            'website'             => $b->website ?? null,
            'reauditCadenceDays'  => (int)($b->reaudit_cadence_days ?? 90),
            'nextReauditAt'       => $b->next_reaudit_at ?? null,
            'lastAuditedAt'       => $b->last_audited_at ?? null,
            'currentRcs'          => isset($b->current_rcs) && $b->current_rcs !== null ? (int)$b->current_rcs : null,
            'currentDitChatgpt'   => isset($b->current_dit_chatgpt) ? (int)$b->current_dit_chatgpt : null,
            'currentDitGemini'    => isset($b->current_dit_gemini) ? (int)$b->current_dit_gemini : null,
            'currentDitPerplexity'=> isset($b->current_dit_perplexity) ? (int)$b->current_dit_perplexity : null,
            'currentDitGrok'      => isset($b->current_dit_grok) ? (int)$b->current_dit_grok : null,
            'currentRar'          => isset($b->current_rar) && $b->current_rar ? (float)$b->current_rar : null,
            'adVerdict'           => $b->current_ad_verdict ?? null,
            'createdAt'           => $b->created_at,
            'updatedAt'           => $b->updated_at,
        ];
    }

    private function getAuditDetail(int $auditId, object $brand): array
    {
        $audit  = DB::table('meridian_audits')->find($auditId);
        $result = DB::table('meridian_brand_audit_results')->where('audit_id', $auditId)->first();

        $probeRuns = DB::table('meridian_probe_runs')
            ->where('audit_id', $auditId)->where('status', 'completed')->get();

        $rcsTotal  = $result ? (int)$result->rcs_total : null;
        $adVerdict = $result ? $result->ad_verdict : null;

        $rcs = $rcsTotal !== null ? [
            'total'             => $rcsTotal,
            'genericPresence'   => $this->computeGenericPresenceScore($probeRuns),
            'ditTiming'         => $this->computeDitTimingScore($probeRuns),
            'handoffCapture'    => $this->computeHandoffCaptureScore($probeRuns),
            'mechanismSeverity' => max(0, 100 - $rcsTotal),
            'previousRcs'       => null,
            'movement'          => null,
            'adVerdict'         => $adVerdict,
        ] : null;

        $rar = null;
        if (isset($brand->annual_sales) && $brand->annual_sales && $rcsTotal !== null) {
            $visibilityGap = max(0, 1 - ($rcsTotal / 100));
            $rarToday      = (float)$brand->annual_sales * 0.40 * $visibilityGap * 0.15 * 0.40;
            $rar = [
                'annualSales'    => (float)$brand->annual_sales,
                'currency'       => $brand->annual_sales_currency ?? 'GBP',
                'rarToday'       => round($rarToday),
                'rar6mo'         => round($rarToday * 1.6),
                'rar12mo'        => round($rarToday * 2.0),
                'discoveryShare' => 0.40,
                'visibilityGap'  => round($visibilityGap, 2),
                'llmShareToday'  => 0.15,
            ];
        }

        $journeyRuns = $result
            ? json_decode($result->journey_runs ?? '[]', true)
            : $this->buildJourneyRunsFromProbes($probeRuns);

        $adVerdicts = $result
            ? json_decode($result->ad_verdicts ?? '[]', true)
            : $this->buildAdVerdictsFromProbes($probeRuns, $rcsTotal ?? 0, $adVerdict ?? 'do_not_advertise', $brand->name);

        $brief = $result ? json_decode($result->citation_brief ?? 'null', true) : null;

        $probeRunDetails = $this->getProbeRunDetails($probeRuns, $brand->name);

        return [
            'auditId'         => $auditId,
            'completedAt'     => $audit->completed_at,
            'auditType'       => $audit->audit_type,
            'instrumentType'  => $audit->instrument_type ?? 'directed_bjp',
            'platforms'       => json_decode($audit->platforms ?? '[]', true),
            'rcs'             => $rcs,
            'rar'             => $rar,
            'journeyRuns'     => $journeyRuns ?? [],
            'adVerdicts'      => $adVerdicts  ?? [],
            'citationBrief'   => $brief,
            'probeRunDetails' => $probeRunDetails,
            'dpaRuns'         => [],
        ];
    }

    private function computeGenericPresenceScore($probeRuns): int
    {
        $genericRuns = $probeRuns->where('probe_mode', 'generic');
        if ($genericRuns->isEmpty()) return 0;
        $rc    = json_decode($genericRuns->first()->raw_config ?? '{}', true);
        $score = (int)($rc['probe_score'] ?? 0);
        return $score >= 50 ? 25 : ($score > 0 ? 12 : 0);
    }

    private function computeDitTimingScore($probeRuns): int
    {
        $anchoredRuns = $probeRuns->where('probe_mode', 'anchored');
        if ($anchoredRuns->isEmpty()) return 0;
        $ditTurns = $anchoredRuns->pluck('dit_turn')->filter()->values();
        if ($ditTurns->isEmpty()) return 25;
        $avgDit = $ditTurns->avg();
        if ($avgDit >= 4) return 25;
        if ($avgDit >= 3) return 18;
        if ($avgDit >= 2) return 10;
        return 4;
    }

    private function computeHandoffCaptureScore($probeRuns): int
    {
        $anchoredRuns = $probeRuns->where('probe_mode', 'anchored');
        if ($anchoredRuns->isEmpty()) return 0;
        $survived = $anchoredRuns->filter(fn($r) => $r->t4_winner === null)->count();
        $total    = $anchoredRuns->count();
        return $total > 0 ? (int)round(($survived / $total) * 25) : 0;
    }

    private function buildJourneyRunsFromProbes($probeRuns): array
    {
        return $probeRuns->map(function ($run) {
            $rc = json_decode($run->raw_config ?? '{}', true);
            return [
                'platform'        => $run->platform,
                'probeMode'       => $run->probe_mode,
                'totalTurns'      => (int)$run->turns_completed,
                'ditTurn'         => $run->dit_turn ? (int)$run->dit_turn : null,
                'ditType'         => $rc['dit_type'] ?? null,
                'displacingBrand' => $run->t4_winner,
                'handoffTurn'     => (int)($run->handoff_turn ?? 4),
                'brandAtHandoff'  => $run->t4_winner ? 'absent' : 'present',
                'terminationType' => $run->termination_type ?? 'turn_limit',
                'genericResult'   => $run->probe_mode === 'generic'
                    ? ($run->t4_winner ? 'absent' : 'present') : null,
            ];
        })->values()->toArray();
    }

    private function buildAdVerdictsFromProbes($probeRuns, int $rcs, string $verdict, string $brandName = ''): array
    {
        $platforms = $probeRuns->pluck('platform')->unique()->values();
        $result    = [];

        $platformContext = [
            'chatgpt'    => 'GPT-4o uses training data citations. Displacement is structural and requires T1/T2 authority intervention.',
            'gemini'     => 'Gemini activates Educational Drift Arc — displaces early via clinical/educational framing. Requires brand-specific content density.',
            'perplexity' => 'Perplexity uses live retrieval. Displacement is volatile and can be addressed faster via T3 content. URLs are captured directly.',
            'grok'       => 'Grok requires primary criterion ownership. Displacement responds to definitional authority in training data.',
        ];

        foreach ($platforms as $platform) {
            $runs     = $probeRuns->where('platform', $platform);
            $anchored = $runs->firstWhere('probe_mode', 'anchored');
            $generic  = $runs->firstWhere('probe_mode', 'generic');
            if (!$anchored) continue;

            $rc            = json_decode($anchored->raw_config ?? '{}', true);
            $platformScore = (int)($rc['probe_score'] ?? 0);
            $t4Winner      = $anchored->t4_winner;
            $brandSurvivedT4 = ($t4Winner === null)
                || (mb_strtolower(trim($t4Winner)) === mb_strtolower(trim($brandName)))
                || ($anchored->dit_turn === null);

            $genericSurvived = !$generic || ($generic->t4_winner === null)
                || (mb_strtolower(trim($generic->t4_winner ?? '')) === mb_strtolower(trim($brandName)));

            if ($brandSurvivedT4 && $genericSurvived) {
                $platformVerdict = $platformScore >= 70 ? 'amplification_ready' : 'monitor';
            } elseif ($brandSurvivedT4) {
                $platformVerdict = $platformScore >= 50 ? 'monitor' : 'do_not_advertise';
            } else {
                $platformVerdict = $platformScore >= 70 ? 'monitor' : 'do_not_advertise';
            }

            $ditTurn   = $anchored->dit_turn ? (int)$anchored->dit_turn : null;
            $ditAssess = $ditTurn === null ? 'pass' : ($ditTurn <= 2 ? 'fail' : 'warn');
            $competitor = ($t4Winner && !$brandSurvivedT4) ? $t4Winner : null;
            $ditType = $rc['dit_type'] ?? null;
            $mechanismLabel = match($ditType) {
                'evaluative'  => 'criteria-based evaluation filter',
                'comparative' => 'competitive comparison displacement',
                'multi_axis'  => 'multi-axis lifestyle fit filter',
                default       => 'citation layer displacement',
            };

            $result[] = [
                'platform'               => $platform,
                'verdict'                => $platformVerdict,
                'platformContext'        => $platformContext[$platform] ?? '',
                'verdictRationale'       => $competitor
                    ? "Brand displaced at T4 by {$competitor} via {$mechanismLabel}. Ad spend would appear in hostile context."
                    : ($brandSurvivedT4
                        ? "Brand holds T4 purchase position. Reasoning chain supports commercial handoff."
                        : "Brand absent at T4. Purchase routing captured by competitor."),
                'q1ReasoningChain'       => $ditTurn ? ($ditTurn <= 2 ? 'fail' : 'warn') : 'pass',
                'q1Detail'               => $ditTurn
                    ? "DIT fires at T{$ditTurn} — brand loses primary citation position via {$mechanismLabel}."
                    : 'Brand holds primary citation position across all four turns.',
                'q2HandoffCapture'       => $brandSurvivedT4 ? 'pass' : 'fail',
                'q2Detail'               => $brandSurvivedT4
                    ? 'Brand present at T4 commercial handoff. Purchase routing confirmed.'
                    : ($competitor ? "T4 handoff captured by {$competitor}. Brand absent at purchase decision." : 'Brand absent at T4 commercial handoff.'),
                'q3GenericConsideration' => $genericSurvived ? 'pass' : 'fail',
                'q3Detail'               => $genericSurvived
                    ? 'Brand appears in unprompted category recommendations on this platform.'
                    : ($generic?->t4_winner ? "Generic probe routes to {$generic->t4_winner} — brand absent from spontaneous recommendations." : 'Brand absent from generic category probes on this platform.'),
                'q4Displacement'         => $ditAssess,
                'q4Detail'               => $ditTurn
                    ? "DIT T{$ditTurn} — " . ($ditTurn <= 2 ? 'early structural displacement. Brand loses position before criteria evaluation.' : 'late-stage displacement at criteria/purchase turn. Brand survives awareness and comparison.')
                    : 'No displacement detected. Brand holds primary position across all evaluation stages.',
                'q5Rar'                  => $rcs < 40 ? 'fail' : ($rcs < 70 ? 'warn' : 'pass'),
                'q5Detail'               => "Platform RCS {$platformScore}/100. " . ($competitor ? "Revenue at risk from {$competitor} capture." : "Brand holds purchase position."),
                'rcsGapToAmplification'  => max(0, 70 - $platformScore),
                'recommendedIntervention'=> null,
            ];
        }

        return $result;
    }

    private function getProbeRunDetails($probeRuns, string $brandName): array
    {
        $details = [];

        foreach ($probeRuns as $run) {
            $turns = DB::table('meridian_probe_turns')
                ->where('probe_run_id', $run->id)
                ->orderBy('turn_number')
                ->get();

            $turnsOut = $turns->map(function ($turn) use ($brandName) {
                $citData    = json_decode($turn->citation_urls ?? '{}', true);
                $annotation = $citData['annotation'] ?? null;
                $urls       = $citData['urls'] ?? [];

                $classifiedUrls = array_map(function ($url) use ($brandName) {
                    $host = '';
                    try { $host = parse_url($url, PHP_URL_HOST) ?? ''; } catch (\Throwable $e) {}
                    $host = strtolower(str_replace('www.', '', $host));

                    $type = 'general_web';
                    if (str_contains($host, strtolower(str_replace(' ', '', $brandName))) ||
                        str_contains($host, strtolower(explode(' ', $brandName)[0]))) {
                        $type = 'brand_com';
                    } elseif (preg_match('/sephora|ulta|lookfantastic|boots|asos|amazon|nordstrom|net-a-porter|dermstore|cultbeauty/', $host)) {
                        $type = 'retailer';
                    } elseif (preg_match('/reddit|mumsnet|makeupally|beautyboard|forum/', $host)) {
                        $type = 'community';
                    } elseif (preg_match('/vogue|allure|byrdie|harpers|elle|glamour|refinery29|cosmopolitan|instyle/', $host)) {
                        $type = 'editorial';
                    } elseif (preg_match('/ncbi|nih|pubmed|aad\.org|dermatol|healthline|webmd|mayoclinic/', $host)) {
                        $type = 'clinical';
                    }

                    return ['url' => $url, 'host' => $host, 'destination_type' => $type];
                }, $urls);

                return [
                    'turnNumber'     => (int)$turn->turn_number,
                    'userPrompt'     => $turn->user_prompt,
                    'brandPresence'  => $turn->brand_presence,
                    'isDitTurn'      => (bool)$turn->is_dit_turn,
                    'isHandoffTurn'  => (bool)$turn->is_handoff_turn,
                    'isAcceptPhrase' => (bool)($turn->is_acceptance_phrase ?? false),
                    'annotation'     => $annotation,
                    'citationUrls'   => $classifiedUrls,
                ];
            })->values()->toArray();

            $t1Types = collect($turnsOut[0]['annotation']['source_types'] ?? [])->pluck('type')->toArray();
            $survivalRates = array_map(function ($turn) use ($t1Types) {
                $turnTypes = collect($turn['annotation']['source_types'] ?? [])->pluck('type')->toArray();
                $survived  = count(array_intersect($t1Types, $turnTypes));
                return $t1Types ? round(($survived / count($t1Types)) * 100) : 0;
            }, $turnsOut);

            $rc = json_decode($run->raw_config ?? '{}', true);

            $details[] = [
                'probeRunId'    => (int)$run->id,
                'platform'      => $run->platform,
                'probeMode'     => $run->probe_mode,
                'instrument'    => $run->instrument ?? 'DPA',
                'status'        => $run->status,
                'ditTurn'       => $run->dit_turn ? (int)$run->dit_turn : null,
                'ditType'       => $rc['dit_type'] ?? null,
                't4Winner'      => $run->t4_winner,
                'probeScore'    => (int)($rc['probe_score'] ?? 0),
                'turns'         => $turnsOut,
                't1SourceTypes' => $t1Types,
                'survivalRates' => $survivalRates,
            ];
        }

        return $details;
    }

    private function savePrompts(int $brandId, int $agencyId, array $prompts): void
    {
        $validTypes = ['anchored_t1', 'generic_t1', 'dpa_t1', 'dpa_t2', 'dpa_t3', 'dpa_t4'];
        foreach ($prompts as $type => $text) {
            if (!in_array($type, $validTypes)) continue;
            $text = trim($text);
            if (!$text) continue;
            $existing = DB::table('meridian_brand_prompts')
                ->where('brand_id', $brandId)->where('prompt_type', $type)->where('is_active', true)->first();
            if ($existing) {
                DB::table('meridian_brand_prompts')->where('id', $existing->id)
                    ->update(['prompt_text' => $text, 'version' => $existing->version + 1, 'updated_at' => now()]);
            } else {
                DB::table('meridian_brand_prompts')->insert([
                    'brand_id' => $brandId, 'agency_id' => $agencyId, 'prompt_type' => $type,
                    'prompt_text' => $text, 'is_active' => true, 'version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
