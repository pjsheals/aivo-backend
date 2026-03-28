<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;

class ProbeDataController
{
    public function store(): void
    {
        $body = request_body();

        $brand    = trim($body['brand']    ?? '');
        $category = trim($body['category'] ?? '');
        $dsov     = intval($body['dsov_score'] ?? 0);
        $band     = trim($body['band']     ?? '');

        if (!$brand || !$category || !$band) {
            abort(422, 'Missing required fields: brand, category, band');
        }

        $t1 = (bool)($body['t1_validated'] ?? false);
        $t2 = (bool)($body['t2_survives']  ?? false);
        $t3 = (bool)($body['t3_survives']  ?? false);
        $t4 = (bool)($body['t4_wins']      ?? false);

        $displacement = null;
        if ($t4)     $displacement = null;
        elseif ($t3) $displacement = 'T4';
        elseif ($t2) $displacement = 'T3';
        elseif ($t1) $displacement = 'T2';
        else         $displacement = 'T1';

        $previousProbe = Capsule::table('agent_probes')
            ->where('brand', $brand)
            ->where('category', $category)
            ->orderBy('created_at', 'desc')
            ->first();

        $isRepeat        = $previousProbe !== null;
        $previousProbeId = $previousProbe?->id ?? null;

        $data = [
            'brand'             => $brand,
            'category'          => $category,
            'vertical'          => $body['vertical'] ?? null,
            'dsov_score'        => $dsov,
            'band'              => $band,
            'oai_score'         => isset($body['oai_score'])  ? intval($body['oai_score'])  : null,
            'pplx_score'        => isset($body['pplx_score']) ? intval($body['pplx_score']) : null,
            't1_validated'      => $t1,
            't1_present_oai'    => (bool)($body['t1_present_oai']  ?? $t1),
            't1_present_pplx'   => (bool)($body['t1_present_pplx'] ?? $t1),
            't2_survives'       => $t2,
            't3_survives'       => $t3,
            't4_wins'           => $t4,
            'oai_wins_t4'       => (bool)($body['oai_wins_t4']  ?? false),
            'pplx_wins_t4'      => (bool)($body['pplx_wins_t4'] ?? false),
            'displacement_turn' => $displacement,
            't2_competitors'    => isset($body['t2_competitors']) ? json_encode($body['t2_competitors']) : null,
            't4_winner'         => $body['t4_winner'] ?? null,
            'rar_annual'        => isset($body['rar_annual'])   ? intval($body['rar_annual'])   : null,
            'rar_monthly'       => isset($body['rar_monthly'])  ? intval($body['rar_monthly'])  : null,
            'revenue_used'      => isset($body['revenue_used']) ? intval($body['revenue_used']) : null,
            'contact_email'     => $body['contact_email']     ?? null,
            'contact_seniority' => $body['contact_seniority'] ?? null,
            'contact_state'     => $body['contact_state']     ?? null,
            'contact_company'   => $body['contact_company']   ?? null,
            'email_sent'        => (bool)($body['email_sent'] ?? false),
            'email_sent_at'     => ($body['email_sent'] ?? false) ? date('Y-m-d H:i:s') : null,
            'email_type'        => $body['email_type'] ?? 'email_1',
            'source'            => $body['source'] ?? 'agent_paul',
            'is_repeat'         => $isRepeat,
            'previous_probe_id' => $previousProbeId,
            'probe_version'     => $body['probe_version'] ?? 'v1',
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        $id = Capsule::table('agent_probes')->insertGetId($data);

        header('Content-Type: application/json');
        http_response_code(201);
        echo json_encode(['success' => true, 'probe_id' => $id, 'is_repeat' => $isRepeat]);
        exit;
    }

    public function stats(): void
    {
        $pw = $_GET['pw'] ?? '';
        if ($pw !== env('ADMIN_PASSWORD', 'aivo-admin-2026')) {
            abort(401, 'Unauthorised');
        }

        $category = $_GET['category'] ?? null;
        $source   = $_GET['source']   ?? null;
        $days     = intval($_GET['days'] ?? 30);
        $since    = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $query = Capsule::table('agent_probes')->where('created_at', '>=', $since);
        if ($category) $query->where('category', $category);
        if ($source)   $query->where('source', $source);

        $total = (clone $query)->count();

        if ($total === 0) {
            header('Content-Type: application/json');
            echo json_encode(['total' => 0, 'message' => 'No data in range']);
            exit;
        }

        $avgDsov    = round((clone $query)->avg('dsov_score'), 1);
        $bands      = (clone $query)->selectRaw('band, count(*) as count')->groupBy('band')->get();
        $validated  = (clone $query)->where('t1_validated', true)->count();
        $displaced  = (clone $query)->where('t1_validated', true)->where('t4_wins', false)->count();
        $won        = (clone $query)->where('t4_wins', true)->count();
        $dispStages = (clone $query)->selectRaw('displacement_turn, count(*) as count')->groupBy('displacement_turn')->get();
        $repeats    = (clone $query)->where('is_repeat', true)->count();

        $t1Flips = 0;
        if ($repeats > 0) {
            $t1Flips = Capsule::table('agent_probes as a')
                ->join('agent_probes as b', 'a.previous_probe_id', '=', 'b.id')
                ->where('a.created_at', '>=', $since)
                ->whereRaw('a.t1_validated != b.t1_validated')
                ->count();
        }

        $t4Winners  = (clone $query)->whereNotNull('t4_winner')->where('t4_wins', false)
            ->selectRaw('t4_winner, count(*) as count')->groupBy('t4_winner')
            ->orderByDesc('count')->limit(10)->get();

        $byCategory = (clone $query)
            ->selectRaw('category, count(*) as total, avg(dsov_score) as avg_dsov, sum(case when t4_wins then 1 else 0 end) as wins')
            ->groupBy('category')->orderByDesc('total')->get();

        header('Content-Type: application/json');
        echo json_encode([
            'period_days'         => $days,
            'total_probes'        => $total,
            'avg_dsov'            => $avgDsov,
            'bands'               => $bands,
            'validated_t1'        => $validated,
            'validated_t1_pct'    => round($validated / $total * 100, 1),
            'displaced'           => $displaced,
            'displaced_pct'       => $validated > 0 ? round($displaced / $validated * 100, 1) : 0,
            'won_t4'              => $won,
            'won_t4_pct'          => round($won / $total * 100, 1),
            'displacement_stages' => $dispStages,
            'repeat_probes'       => $repeats,
            't1_volatility_flips' => $t1Flips,
            't1_volatility_pct'   => $repeats > 0 ? round($t1Flips / $repeats * 100, 1) : 0,
            'top_t4_winners'      => $t4Winners,
            'by_category'         => $byCategory,
        ]);
        exit;
    }
}
