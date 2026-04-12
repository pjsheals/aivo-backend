<?php

/**
 * run_audit.php — Background worker for Meridian audit execution
 *
 * Called by MeridianAuditController::initiate() via exec():
 *   php /app/workers/run_audit.php {auditId}
 *
 * Runs outside HTTP context. Bootstraps its own DB connection.
 * Updates meridian_audits and meridian_probe_runs throughout.
 */

declare(strict_types=1);

// ── Guard: CLI only ───────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$auditId = isset($argv[1]) ? (int)$argv[1] : null;
if (!$auditId) {
    error_log('[AuditWorker] No audit ID supplied — exiting');
    exit(1);
}

error_log("[AuditWorker] Starting — auditId={$auditId}");

// ── Bootstrap — mirrors exactly what the web app does ─────────────
// Worker lives at app/workers/run_audit.php
// BASE_PATH resolves to /app (same as public/index.php's dirname(__DIR__))
define('BASE_PATH', realpath(__DIR__ . '/..'));

if (!BASE_PATH || !file_exists(BASE_PATH . '/vendor/autoload.php')) {
    error_log('[AuditWorker] Cannot resolve app root or vendor/autoload.php');
    exit(1);
}

require BASE_PATH . '/vendor/autoload.php';

// Load .env (no-op on Railway where vars are injected directly)
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
try { $dotenv->load(); } catch (\Throwable $e) {}

// Use the identical bootstrap as the web app — same DB connection, same helpers
// Note: src/bootstrap.php also runs migrations (idempotent) and initialises Stripe.
// Both are safe to run in worker context.
require BASE_PATH . '/src/bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

// ── Load audit ────────────────────────────────────────────────────
$audit = DB::table('meridian_audits')->find($auditId);
if (!$audit) {
    error_log("[AuditWorker] Audit {$auditId} not found");
    exit(1);
}

if (!in_array($audit->status, ['queued', 'running'], true)) {
    error_log("[AuditWorker] Audit {$auditId} has status '{$audit->status}' — skipping");
    exit(0);
}

$brand = DB::table('meridian_brands')->find($audit->brand_id);
if (!$brand) {
    error_log("[AuditWorker] Brand {$audit->brand_id} not found");
    exit(1);
}

// ── Mark audit as running ─────────────────────────────────────────
DB::table('meridian_audits')->where('id', $auditId)->update([
    'status'     => 'running',
    'started_at' => now(),
    'updated_at' => now(),
]);

error_log("[AuditWorker] Brand: {$brand->name} | Agency: {$audit->agency_id}");

// ── Get probe runs in order ───────────────────────────────────────
// Run anchored probes first, then generic, ordered by platform
$probeRuns = DB::table('meridian_probe_runs')
    ->where('audit_id', $auditId)
    ->whereIn('status', ['queued'])
    ->orderByRaw("CASE probe_mode WHEN 'anchored' THEN 0 ELSE 1 END")
    ->orderBy('platform')
    ->get();

if ($probeRuns->isEmpty()) {
    error_log("[AuditWorker] No queued probe runs for audit {$auditId}");
    DB::table('meridian_audits')->where('id', $auditId)->update([
        'status'          => 'failed',
        'error_message'   => 'No probe runs found',
        'updated_at'      => now(),
    ]);
    exit(1);
}

$probesTotal     = (int)$audit->probes_total;
$probesCompleted = 0;
$probesFailed    = 0;

// ── Instantiate engine ────────────────────────────────────────────
$engine = new \Aivo\Meridian\MeridianProbeEngine();

// ── Execute probes sequentially ───────────────────────────────────
foreach ($probeRuns as $run) {
    error_log("[AuditWorker] Running probe: {$run->platform}/{$run->probe_mode} (run_id={$run->id})");

    $success = $engine->run((int)$run->id);

    if ($success) {
        $probesCompleted++;
    } else {
        $probesFailed++;
        error_log("[AuditWorker] Probe failed: {$run->platform}/{$run->probe_mode}");
    }

    // Update audit progress after each probe
    $pct = $probesTotal > 0 ? (int)round(($probesCompleted / $probesTotal) * 100) : 0;
    DB::table('meridian_audits')->where('id', $auditId)->update([
        'probes_completed' => $probesCompleted,
        'percent_complete' => $pct,
        'updated_at'       => now(),
    ]);

    error_log("[AuditWorker] Progress: {$probesCompleted}/{$probesTotal} ({$pct}%)");
}

// ── All probes done — compute results ────────────────────────────
error_log("[AuditWorker] All probes complete. Computing RCS and results.");

$rcs     = $engine->computeAuditRcs($auditId);
$verdict = $engine->determineVerdict($rcs);

error_log("[AuditWorker] RCS={$rcs} Verdict={$verdict}");

// ── Generate Citation Brief ───────────────────────────────────────
$brief = null;
try {
    $brief = $engine->generateBrief($brand->name, $brand->category ?: 'product', $auditId);
    if ($brief) {
        error_log('[AuditWorker] Brief generated successfully');
    } else {
        error_log('[AuditWorker] Brief generation returned null');
    }
} catch (\Throwable $e) {
    error_log('[AuditWorker] Brief generation failed: ' . $e->getMessage());
}

// ── Build journeyRuns summary for brand_audit_results ─────────────
$completedRuns = DB::table('meridian_probe_runs')
    ->where('audit_id', $auditId)
    ->where('status', 'completed')
    ->get();

$journeyRuns = $completedRuns->map(function ($run) {
    // Get final turn data for handoff info
    $finalTurn = DB::table('meridian_probe_turns')
        ->where('probe_run_id', $run->id)
        ->orderByDesc('turn_num')
        ->first();

    $finalAnno = $finalTurn ? json_decode($finalTurn->annotation ?? '{}', true) : [];

    return [
        'platform'        => $run->platform,
        'probeMode'       => $run->probe_mode,
        'totalTurns'      => (int)$run->turns_completed,
        'ditTurn'         => $run->dit_turn ? (int)$run->dit_turn : null,
        'ditType'         => $run->dit_type,
        'displacingBrand' => $run->t4_winner,
        'handoffTurn'     => 4,
        'brandAtHandoff'  => ($finalTurn && $finalTurn->brand_citation_survived) ? 'present' : 'absent',
        'terminationType' => 'turn_limit',
        'genericResult'   => $run->probe_mode === 'generic'
            ? (($finalTurn && $finalTurn->brand_citation_survived) ? 'present' : 'absent')
            : null,
    ];
})->toArray();

// ── Build adVerdicts summary ──────────────────────────────────────
$adVerdicts = [];
$platforms  = array_unique($completedRuns->pluck('platform')->toArray());

foreach ($platforms as $platform) {
    $platformRuns = $completedRuns->where('platform', $platform);
    $anchoredRun  = $platformRuns->firstWhere('probe_mode', 'anchored');

    if (!$anchoredRun) continue;

    $turns = DB::table('meridian_probe_turns')
        ->where('probe_run_id', $anchoredRun->id)
        ->orderBy('turn_num')
        ->get();

    // Five pre-investment questions
    $t1Pass = (bool)($turns->firstWhere('turn_num', 1)?->brand_citation_survived ?? false);
    $t4Pass = (bool)($turns->firstWhere('turn_num', 4)?->brand_citation_survived ?? false);
    $t4Turn = $turns->firstWhere('turn_num', 4);
    $t4Anno = $t4Turn ? json_decode($t4Turn->annotation ?? '{}', true) : [];

    $genericRun   = $platformRuns->firstWhere('probe_mode', 'generic');
    $genericT4    = $genericRun
        ? DB::table('meridian_probe_turns')->where('probe_run_id', $genericRun->id)->where('turn_num', 4)->first()
        : null;
    $genericPass  = (bool)($genericT4?->brand_citation_survived ?? false);

    $platformScore = (int)($anchoredRun->probe_score ?? 0);
    $platformVerdict = $engine->determineVerdict($platformScore);

    $adVerdicts[] = [
        'platform'              => $platform,
        'verdict'               => $platformVerdict,
        'verdictRationale'      => $anchoredRun->t4_winner
            ? "Brand absent at T4. {$anchoredRun->t4_winner} captures the routing."
            : ($t4Pass ? 'Brand survives to T4 purchase decision.' : 'Brand absent at T4 purchase decision.'),
        'q1ReasoningChain'      => $t1Pass ? 'pass' : 'fail',
        'q1Detail'              => $t1Pass ? 'Brand cited at T1 awareness baseline.' : 'Brand absent at T1.',
        'q2HandoffCapture'      => $t4Pass ? 'pass' : 'fail',
        'q2Detail'              => $t4Pass ? 'Brand present at T4 purchase decision.' : 'Brand absent at T4. Competitor captures routing.',
        'q3GenericConsideration'=> $genericPass ? 'pass' : 'fail',
        'q3Detail'              => $genericPass ? 'Brand appears in generic category probes.' : 'Brand absent from generic probes on this platform.',
        'q4Displacement'        => ($anchoredRun->dit_turn && $anchoredRun->dit_turn <= 2) ? 'fail' : 'warn',
        'q4Detail'              => $anchoredRun->dit_turn ? "DIT fires at T{$anchoredRun->dit_turn}." : 'No displacement detected.',
        'q5Rar'                 => $rcs < 40 ? 'fail' : ($rcs < 70 ? 'warn' : 'pass'),
        'q5Detail'              => "RCS {$rcs}. " . ($brand->annual_sales
            ? 'Revenue at risk calculated from annual sales data.'
            : 'Annual sales not provided — RAR estimate unavailable.'),
        'rcsGapToAmplification' => max(0, 70 - $platformScore),
        'recommendedIntervention' => $brief['interventions'][0]['action'] ?? null,
    ];
}

// ── Compute RAR ───────────────────────────────────────────────────
// RAR = AnnualSales × 0.40 (discovery share) × (1 - rcs/100) × 0.15 (LLM share) × 0.40 (conservatism)
$rar = null;
if ($brand->annual_sales) {
    $visibilityGap = max(0, 1 - ($rcs / 100));
    $rar = (float)$brand->annual_sales * 0.40 * $visibilityGap * 0.15 * 0.40;
}

// ── Store audit results ───────────────────────────────────────────
try {
    // Check if result record exists (shouldn't, but be safe)
    $existing = DB::table('meridian_brand_audit_results')
        ->where('audit_id', $auditId)
        ->first();

    $resultData = [
        'agency_id'          => $audit->agency_id,
        'brand_id'           => $audit->brand_id,
        'audit_id'           => $auditId,
        'rcs_total'          => $rcs,
        'ad_verdict'         => $verdict,
        'dit_timing_score'   => $completedRuns->min('dit_turn') ?? null,
        'journey_runs'       => json_encode($journeyRuns),
        'ad_verdicts'        => json_encode($adVerdicts),
        'citation_brief'     => $brief ? json_encode($brief) : null,
        'revenue_at_risk'    => $rar,
        'updated_at'         => now(),
    ];

    if ($existing) {
        DB::table('meridian_brand_audit_results')
            ->where('audit_id', $auditId)
            ->update($resultData);
    } else {
        DB::table('meridian_brand_audit_results')->insert(array_merge($resultData, [
            'created_at' => now(),
        ]));
    }

    // Update brand's current RCS + verdict for dashboard cards
    DB::table('meridian_brands')->where('id', $audit->brand_id)->update([
        'current_rcs'      => $rcs,
        'ad_verdict'       => $verdict,
        'last_audited_at'  => now(),
        'updated_at'       => now(),
    ]);

    error_log("[AuditWorker] Results stored. RCS={$rcs} Verdict={$verdict} RAR=" . ($rar ? number_format($rar) : 'N/A'));

} catch (\Throwable $e) {
    error_log('[AuditWorker] Failed to store results: ' . $e->getMessage());
}

// ── Mark audit complete ───────────────────────────────────────────
$finalStatus = ($probesFailed === $probesTotal) ? 'failed' : 'completed';

DB::table('meridian_audits')->where('id', $auditId)->update([
    'status'           => $finalStatus,
    'probes_completed' => $probesCompleted,
    'percent_complete' => $probesCompleted > 0 ? 100 : 0,
    'completed_at'     => now(),
    'updated_at'       => now(),
]);

error_log("[AuditWorker] Done. auditId={$auditId} status={$finalStatus} probes={$probesCompleted}/{$probesTotal}");
exit(0);
