<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianCorpusAnalyser
 *
 * Computes daily corpus snapshots per platform and detects
 * correlated cross-category behaviour shifts that indicate
 * an LLM model update has occurred.
 *
 * Called lazily from the Model Watch admin endpoint — runs
 * once per day maximum, storing snapshots for comparison.
 */
class MeridianCorpusAnalyser
{
    // ── Detection thresholds ─────────────────────────────────────
    private const DIT_SHIFT_THRESHOLD      = 0.40;  // absolute turns
    private const DPA_SHIFT_THRESHOLD      = 0.12;  // 12% relative
    private const T4_STABILITY_THRESHOLD   = 0.08;  // 8 ppt
    private const DRIFT_ARC_THRESHOLD      = 0.08;  // 8 ppt
    private const PSOS_SHIFT_THRESHOLD     = 0.10;  // 10% relative

    private const MIN_SAMPLE_SIZE          = 5;     // signals needed to snapshot
    private const MIN_CATEGORIES_FOR_ALERT = 2;     // cross-category threshold
    private const MIN_METRICS_FOR_ALERT    = 2;     // how many metrics must shift
    private const ALERT_COOLDOWN_DAYS      = 3;     // don't re-alert within N days

    private const PLATFORMS = ['chatgpt', 'gemini', 'perplexity', 'grok'];

    // ── Public: run full daily analysis ─────────────────────────
    public function runDailyAnalysis(): array
    {
        $today = date('Y-m-d');

        $this->takeSnapshot($today);
        $newAlerts = $this->detectAnomalies($today);

        return [
            'snapshot_date' => $today,
            'alerts_fired'  => count($newAlerts),
            'new_alerts'    => $newAlerts,
        ];
    }

    // ── Snapshot: compute rolling 7-day aggregate per platform ──
    public function takeSnapshot(string $date): void
    {
        $windowEnd   = $date . ' 23:59:59';
        $windowStart = date('Y-m-d', strtotime($date . ' -6 days')) . ' 00:00:00';

        foreach (self::PLATFORMS as $platform) {
            // Idempotent — skip if today's snapshot already exists
            $exists = DB::table('meridian_corpus_snapshots')
                ->where('snapshot_date', $date)
                ->where('platform', $platform)
                ->exists();
            if ($exists) continue;

            try {
                $stats = DB::table('meridian_corpus_contributions')
                    ->where('platform', $platform)
                    ->whereBetween('created_at', [$windowStart, $windowEnd])
                    ->selectRaw("
                        COUNT(*) as sample_size,
                        COUNT(DISTINCT category) as categories_covered,
                        AVG(CASE WHEN dit_turn IS NOT NULL THEN dit_turn::numeric END) as avg_dit_turn,
                        AVG(CASE WHEN dpa_total_score IS NOT NULL THEN dpa_total_score::numeric END) as avg_dpa_score,
                        AVG(CASE WHEN psos_composite IS NOT NULL THEN psos_composite::numeric END) as avg_psos_composite,
                        CAST(
                            COUNT(CASE WHEN drift_arc_activated = true THEN 1 END) AS float
                        ) / NULLIF(COUNT(*), 0) as drift_arc_rate
                    ")
                    ->first();

                if (!$stats || (int)$stats->sample_size < self::MIN_SAMPLE_SIZE) continue;

                // T4 stability: brand wins / probes with T4 outcome
                $t4Total = DB::table('meridian_corpus_contributions')
                    ->where('platform', $platform)
                    ->whereBetween('created_at', [$windowStart, $windowEnd])
                    ->whereNotNull('dpa_t4_outcome')
                    ->count();

                $t4Wins = DB::table('meridian_corpus_contributions')
                    ->where('platform', $platform)
                    ->whereBetween('created_at', [$windowStart, $windowEnd])
                    ->where('dpa_t4_outcome', 'brand_wins')
                    ->count();

                $t4Rate = $t4Total > 0 ? round($t4Wins / $t4Total, 4) : null;

                $metricsJson = json_encode([
                    'avg_dit_turn'       => $stats->avg_dit_turn       ? round((float)$stats->avg_dit_turn, 3)       : null,
                    'avg_dpa_score'      => $stats->avg_dpa_score      ? round((float)$stats->avg_dpa_score, 2)      : null,
                    't4_stability_rate'  => $t4Rate,
                    'avg_psos_composite' => $stats->avg_psos_composite ? round((float)$stats->avg_psos_composite, 2) : null,
                    'drift_arc_rate'     => $stats->drift_arc_rate      ? round((float)$stats->drift_arc_rate, 4)    : null,
                ]);

                DB::table('meridian_corpus_snapshots')->insert([
                    'snapshot_date'      => $date,
                    'platform'           => $platform,
                    'avg_dit_turn'       => $stats->avg_dit_turn       ? round((float)$stats->avg_dit_turn, 3)       : null,
                    'avg_dpa_score'      => $stats->avg_dpa_score      ? round((float)$stats->avg_dpa_score, 2)      : null,
                    't4_stability_rate'  => $t4Rate,
                    'avg_psos_composite' => $stats->avg_psos_composite ? round((float)$stats->avg_psos_composite, 2) : null,
                    'drift_arc_rate'     => $stats->drift_arc_rate      ? round((float)$stats->drift_arc_rate, 4)    : null,
                    'sample_size'        => (int)$stats->sample_size,
                    'categories_covered' => (int)$stats->categories_covered,
                    'metrics_json'       => $metricsJson,
                    'created_at'         => now(),
                ]);

            } catch (\Throwable $e) {
                log_error('[CorpusAnalyser] snapshot failed', [
                    'platform' => $platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Anomaly detection: compare current vs 7-day-prior snapshot
    public function detectAnomalies(string $date): array
    {
        $alerts = [];

        foreach (self::PLATFORMS as $platform) {
            try {
                $current = DB::table('meridian_corpus_snapshots')
                    ->where('snapshot_date', $date)
                    ->where('platform', $platform)
                    ->first();

                if (!$current || (int)$current->sample_size < self::MIN_SAMPLE_SIZE) continue;

                // Find nearest prior snapshot (up to 10 days back)
                $priorCutoff = date('Y-m-d', strtotime($date . ' -4 days'));
                $previous = DB::table('meridian_corpus_snapshots')
                    ->where('snapshot_date', '<=', $priorCutoff)
                    ->where('platform', $platform)
                    ->where('sample_size', '>=', self::MIN_SAMPLE_SIZE)
                    ->orderBy('snapshot_date', 'desc')
                    ->first();

                if (!$previous) continue;

                // Cooldown: don't re-alert if we already fired recently
                $recentAlert = DB::table('meridian_model_behaviour_alerts')
                    ->where('platform', $platform)
                    ->where('detected_at', '>=', date('Y-m-d H:i:s', strtotime('-' . self::ALERT_COOLDOWN_DAYS . ' days')))
                    ->exists();
                if ($recentAlert) continue;

                $shifts  = [];
                $metrics = [];

                // ── DIT shift ────────────────────────────────────
                if ($current->avg_dit_turn !== null && $previous->avg_dit_turn !== null) {
                    $delta = (float)$current->avg_dit_turn - (float)$previous->avg_dit_turn;
                    if (abs($delta) >= self::DIT_SHIFT_THRESHOLD) {
                        $dir      = $delta < 0 ? 'earlier' : 'later';
                        $shifts[] = "DIT shifted {$dir} by " . round(abs($delta), 2) . " turns";
                        $metrics['dit'] = [
                            'metric'    => 'Average DIT Turn',
                            'current'   => round((float)$current->avg_dit_turn, 2),
                            'previous'  => round((float)$previous->avg_dit_turn, 2),
                            'delta'     => round($delta, 2),
                            'direction' => $dir,
                        ];
                    }
                }

                // ── DPA score shift ──────────────────────────────
                if ($current->avg_dpa_score !== null && $previous->avg_dpa_score !== null
                    && (float)$previous->avg_dpa_score > 0) {
                    $delta   = (float)$current->avg_dpa_score - (float)$previous->avg_dpa_score;
                    $pct     = abs($delta) / (float)$previous->avg_dpa_score;
                    if ($pct >= self::DPA_SHIFT_THRESHOLD) {
                        $dir      = $delta > 0 ? 'improved' : 'declined';
                        $shifts[] = "DPA score {$dir} by " . round($pct * 100, 1) . "%";
                        $metrics['dpa'] = [
                            'metric'    => 'Avg DPA Score',
                            'current'   => round((float)$current->avg_dpa_score, 1),
                            'previous'  => round((float)$previous->avg_dpa_score, 1),
                            'delta_pct' => round($pct * 100, 1),
                            'direction' => $dir,
                        ];
                    }
                }

                // ── T4 stability ─────────────────────────────────
                if ($current->t4_stability_rate !== null && $previous->t4_stability_rate !== null) {
                    $delta = (float)$current->t4_stability_rate - (float)$previous->t4_stability_rate;
                    if (abs($delta) >= self::T4_STABILITY_THRESHOLD) {
                        $dir      = $delta > 0 ? 'improved' : 'declined';
                        $shifts[] = "T4 brand survival {$dir} by " . round(abs($delta) * 100, 1) . " ppt";
                        $metrics['t4_stability'] = [
                            'metric'    => 'T4 Brand Survival Rate',
                            'current'   => round((float)$current->t4_stability_rate * 100, 1) . '%',
                            'previous'  => round((float)$previous->t4_stability_rate * 100, 1) . '%',
                            'delta_ppt' => round(abs($delta) * 100, 1),
                            'direction' => $dir,
                        ];
                    }
                }

                // ── Drift arc rate ───────────────────────────────
                if ($current->drift_arc_rate !== null && $previous->drift_arc_rate !== null) {
                    $delta = (float)$current->drift_arc_rate - (float)$previous->drift_arc_rate;
                    if (abs($delta) >= self::DRIFT_ARC_THRESHOLD) {
                        $dir      = $delta > 0 ? 'increased' : 'decreased';
                        $shifts[] = "Drift arc activation {$dir} by " . round(abs($delta) * 100, 1) . " ppt";
                        $metrics['drift_arc'] = [
                            'metric'    => 'Drift Arc Activation Rate',
                            'current'   => round((float)$current->drift_arc_rate * 100, 1) . '%',
                            'previous'  => round((float)$previous->drift_arc_rate * 100, 1) . '%',
                            'delta_ppt' => round(abs($delta) * 100, 1),
                            'direction' => $dir,
                        ];
                    }
                }

                // ── PSOS composite ───────────────────────────────
                if ($current->avg_psos_composite !== null && $previous->avg_psos_composite !== null
                    && (float)$previous->avg_psos_composite > 0) {
                    $delta = (float)$current->avg_psos_composite - (float)$previous->avg_psos_composite;
                    $pct   = abs($delta) / (float)$previous->avg_psos_composite;
                    if ($pct >= self::PSOS_SHIFT_THRESHOLD) {
                        $dir      = $delta > 0 ? 'improved' : 'declined';
                        $shifts[] = "PSOS composite {$dir} by " . round($pct * 100, 1) . "%";
                        $metrics['psos'] = [
                            'metric'    => 'Avg PSOS Composite',
                            'current'   => round((float)$current->avg_psos_composite, 1),
                            'previous'  => round((float)$previous->avg_psos_composite, 1),
                            'delta_pct' => round($pct * 100, 1),
                            'direction' => $dir,
                        ];
                    }
                }

                // ── Threshold check ──────────────────────────────
                if (count($shifts) < self::MIN_METRICS_FOR_ALERT) continue;
                if ((int)$current->categories_covered < self::MIN_CATEGORIES_FOR_ALERT) continue;

                $severity = 'moderate';
                if (count($shifts) >= 3) $severity = 'high';
                if (count($shifts) >= 4) $severity = 'critical';

                $platformLabel = ucfirst($platform);
                $summary = "{$platformLabel}: " . implode('; ', $shifts)
                    . " — detected across {$current->categories_covered} categories"
                    . " (n={$current->sample_size} signals, compared to n={$previous->sample_size} on {$previous->snapshot_date})";

                $alertId = DB::table('meridian_model_behaviour_alerts')->insertGetId([
                    'platform'           => $platform,
                    'alert_type'         => 'correlated_behaviour_shift',
                    'severity'           => $severity,
                    'detected_at'        => now(),
                    'summary'            => $summary,
                    'metrics_delta_json' => json_encode($metrics),
                    'categories_affected'=> (int)$current->categories_covered,
                    'sample_size'        => (int)$current->sample_size,
                    'acknowledged'       => false,
                    'created_at'         => now(),
                ]);

                $alert = [
                    'id'                  => $alertId,
                    'platform'            => $platform,
                    'severity'            => $severity,
                    'summary'             => $summary,
                    'categories_affected' => (int)$current->categories_covered,
                    'sample_size'         => (int)$current->sample_size,
                    'detected_at'         => now(),
                    'metrics'             => $metrics,
                ];

                $alerts[] = $alert;

                // Send email
                $this->sendAlertEmail($platform, $severity, $summary, $metrics);

            } catch (\Throwable $e) {
                log_error('[CorpusAnalyser] anomaly detection failed', [
                    'platform' => $platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $alerts;
    }

    // ── Get recent alerts for admin display ──────────────────────
    public function getAlerts(int $limit = 50): array
    {
        try {
            return DB::table('meridian_model_behaviour_alerts')
                ->orderBy('detected_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn($a) => [
                    'id'                  => (int)$a->id,
                    'platform'            => $a->platform,
                    'alert_type'          => $a->alert_type,
                    'severity'            => $a->severity,
                    'summary'             => $a->summary,
                    'metrics'             => $a->metrics_delta_json ? json_decode($a->metrics_delta_json, true) : [],
                    'categories_affected' => (int)$a->categories_affected,
                    'sample_size'         => (int)$a->sample_size,
                    'acknowledged'        => (bool)$a->acknowledged,
                    'acknowledged_by'     => $a->acknowledged_by,
                    'acknowledged_at'     => $a->acknowledged_at,
                    'detected_at'         => $a->detected_at,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Get platform status summary ───────────────────────────────
    public function getPlatformStatus(): array
    {
        $status = [];
        $today  = date('Y-m-d');

        foreach (self::PLATFORMS as $platform) {
            try {
                $latest = DB::table('meridian_corpus_snapshots')
                    ->where('platform', $platform)
                    ->orderBy('snapshot_date', 'desc')
                    ->first();

                $unacknowledged = DB::table('meridian_model_behaviour_alerts')
                    ->where('platform', $platform)
                    ->where('acknowledged', false)
                    ->orderBy('detected_at', 'desc')
                    ->first();

                $status[$platform] = [
                    'platform'          => $platform,
                    'last_snapshot'     => $latest?->snapshot_date,
                    'last_sample_size'  => $latest ? (int)$latest->sample_size : 0,
                    'avg_dit_turn'      => $latest?->avg_dit_turn ? round((float)$latest->avg_dit_turn, 2) : null,
                    'avg_dpa_score'     => $latest?->avg_dpa_score ? round((float)$latest->avg_dpa_score, 1) : null,
                    't4_stability_rate' => $latest?->t4_stability_rate ? round((float)$latest->t4_stability_rate * 100, 1) . '%' : null,
                    'drift_arc_rate'    => $latest?->drift_arc_rate ? round((float)$latest->drift_arc_rate * 100, 1) . '%' : null,
                    'active_alert'      => $unacknowledged ? [
                        'severity'    => $unacknowledged->severity,
                        'detected_at' => $unacknowledged->detected_at,
                    ] : null,
                ];
            } catch (\Throwable $e) {
                $status[$platform] = [
                    'platform'     => $platform,
                    'last_snapshot'=> null,
                    'active_alert' => null,
                ];
            }
        }

        return $status;
    }

    // ── Email alert ───────────────────────────────────────────────
    private function sendAlertEmail(string $platform, string $severity, string $summary, array $metrics): void
    {
        $apiKey = env('RESEND_API_KEY');
        if (!$apiKey) return;

        $severityColors = [
            'critical' => '#dc2626',
            'high'     => '#ea580c',
            'moderate' => '#d97706',
            'low'      => '#2d8a6e',
        ];
        $color = $severityColors[$severity] ?? '#6b7280';

        $metricsRows = '';
        foreach ($metrics as $m) {
            $metricsRows .= "<tr>
                <td style='padding:8px 12px;border-bottom:1px solid #f3f4f6;font-weight:500'>" . htmlspecialchars($m['metric'] ?? '') . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f3f4f6;color:{$color}'>" . htmlspecialchars($m['direction'] ?? '') . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f3f4f6'>" . htmlspecialchars($m['current'] ?? '') . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#9ca3af'>" . htmlspecialchars($m['previous'] ?? '') . "</td>
            </tr>";
        }

        $html = "<!DOCTYPE html><html><body style='margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f9fafb'>
        <div style='max-width:580px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)'>
            <div style='background:#213448;padding:24px 28px'>
                <div style='color:#94B4C1;font-size:11px;letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px'>AIVO Meridian · Model Watch</div>
                <div style='color:#fff;font-size:17px;font-weight:600'>Behaviour shift detected</div>
            </div>
            <div style='padding:24px 28px'>
                <div style='display:inline-block;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:700;background:" . $color . "22;color:{$color};margin-bottom:16px;text-transform:uppercase;letter-spacing:.06em'>" . htmlspecialchars(strtoupper($severity)) . " · " . htmlspecialchars(ucfirst($platform)) . "</div>
                <p style='font-size:14px;color:#374151;line-height:1.6;margin:0 0 20px'>" . htmlspecialchars($summary) . "</p>
                <table style='width:100%;border-collapse:collapse;font-size:13px'>
                    <thead>
                        <tr style='background:#f9fafb'>
                            <th style='padding:8px 12px;text-align:left;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Metric</th>
                            <th style='padding:8px 12px;text-align:left;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Direction</th>
                            <th style='padding:8px 12px;text-align:left;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Current</th>
                            <th style='padding:8px 12px;text-align:left;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em'>Previous</th>
                        </tr>
                    </thead>
                    <tbody>{$metricsRows}</tbody>
                </table>
                <p style='font-size:12px;color:#9ca3af;margin-top:20px'>Acknowledge this alert in the AIVO Meridian admin panel under <strong>Model Watch</strong>.</p>
            </div>
        </div></body></html>";

        $payload = json_encode([
            'from'    => 'AIVO Meridian <paul@aivosearch.org>',
            'to'      => ['paul@aivoedge.net', 'tim@aivoedge.net'],
            'subject' => '[Meridian Model Watch] ' . strtoupper($severity) . ': ' . ucfirst($platform) . ' behaviour shift detected',
            'html'    => $html,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
