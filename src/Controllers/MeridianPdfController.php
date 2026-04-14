<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * MeridianPdfController
 *
 * GET /api/meridian/pdf/remediation?brand_id=X&token=Y
 * Generates and streams a PDF of the remediation report.
 * Token passed as query param (required for browser download links).
 */
class MeridianPdfController
{
    public function remediationReport(): void
    {
        // Auth via query param token (needed for browser download)
        $token = $_GET['token'] ?? '';
        if (!$token) {
            http_response_code(401);
            echo 'Unauthorised';
            return;
        }

        $session = DB::table('meridian_user_sessions')
            ->where('session_token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            http_response_code(401);
            echo 'Invalid or expired session';
            return;
        }

        $agencyId = (int)$session->agency_id;
        $brandId  = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;

        if (!$brandId) {
            http_response_code(422);
            echo 'brand_id required';
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            echo 'Brand not found';
            return;
        }

        // Load remediation data
        $row = DB::table('meridian_brand_audit_results')
            ->where('brand_id', $brandId)
            ->whereNotNull('remediation_json')
            ->orderByDesc('created_at')
            ->first(['remediation_json', 'remediation_generated_at', 'rcs_total', 'ad_verdict']);

        if (!$row) {
            http_response_code(404);
            echo 'No remediation report found for this brand';
            return;
        }

        $data    = json_decode($row->remediation_json, true);
        $rcs     = $row->rcs_total ? (int)$row->rcs_total : null;
        $verdict = $row->ad_verdict ?? null;
        $genAt   = $row->remediation_generated_at ?? null;

        // Load agency for white-label
        $agency = DB::table('meridian_agencies')
            ->where('id', $agencyId)
            ->first(['name', 'logo_url']);

        $html = $this->buildHtml($brand, $agency, $data, $rcs, $verdict, $genAt);

        // Generate PDF
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'AIVO-Meridian-' . preg_replace('/[^a-zA-Z0-9]/', '-', $brand->name) . '-Remediation.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');

        echo $dompdf->output();
        exit;
    }

    private function buildHtml(object $brand, ?object $agency, array $data, ?int $rcs, ?string $verdict, ?string $genAt): string
    {
        $brandName    = htmlspecialchars($brand->name ?? '');
        $category     = htmlspecialchars($brand->category ?? '');
        $agencyName   = htmlspecialchars($agency->name ?? 'AIVO Edge');
        $genDate      = $genAt ? date('j F Y', strtotime($genAt)) : date('j F Y');
        $rcsDisplay   = $rcs !== null ? $rcs . '/100' : '—';
        $verdictLabel = $this->verdictLabel($verdict);
        $verdictColor = $this->verdictColor($verdict);

        // Section 1 — Diagnostic Verdict
        $dv         = $data['diagnostic_verdict'] ?? [];
        $headline   = htmlspecialchars($dv['headline'] ?? '');
        $summary    = htmlspecialchars($dv['summary'] ?? '');
        $severity   = strtoupper($dv['severity'] ?? '');
        $rarContext = htmlspecialchars($dv['rar_context'] ?? '');
        $sevColor   = in_array($dv['severity'] ?? '', ['critical','high']) ? '#c0393b' : ($dv['severity'] === 'moderate' ? '#b07d30' : '#2d8a6e');

        // Section 2 — Platform Displacement
        $pda      = $data['platform_displacement_analysis'] ?? [];
        $platHtml = '';
        foreach (['chatgpt' => 'ChatGPT', 'gemini' => 'Gemini', 'perplexity' => 'Perplexity', 'grok' => 'Grok'] as $key => $label) {
            $p = $pda[$key] ?? null;
            if (!$p || ($p['dit'] ?? '') === 'not_tested') continue;
            $dit          = strtoupper($p['dit'] ?? '—');
            $mechanism    = htmlspecialchars($p['mechanism'] ?? '');
            $diagnosis    = htmlspecialchars($p['diagnosis'] ?? '');
            $intervention = htmlspecialchars($p['intervention'] ?? '');
            $competitors  = implode(', ', array_map('htmlspecialchars', $p['displacing_competitors'] ?? []));
            $ditColor     = in_array($p['dit'] ?? '', ['T1','T2']) ? '#c0393b' : ($p['dit'] === 'null' ? '#2d8a6e' : '#b07d30');
            $platHtml    .= "
            <div class='plat-block'>
                <div class='plat-header'>
                    <span class='plat-name'>{$label}</span>
                    <span class='dit-badge' style='color:{$ditColor}'>DIT {$dit}</span>
                    <span class='mechanism-tag'>{$mechanism}</span>
                </div>
                " . ($competitors ? "<div class='competitors'>Displacing: <strong>{$competitors}</strong></div>" : '') . "
                <p class='diagnosis'>{$diagnosis}</p>
                <div class='intervention'><strong>Intervention:</strong> {$intervention}</div>
            </div>";
        }

        // Section 3 — Citation Gap Matrix
        $cgm     = $data['citation_gap_matrix'] ?? [];
        $cgmHtml = '';
        foreach ($cgm as $gap) {
            $tier        = htmlspecialchars($gap['tier'] ?? '');
            $source      = htmlspecialchars($gap['source_category'] ?? '');
            $status      = strtoupper($gap['current_status'] ?? '');
            $consequence = htmlspecialchars($gap['commercial_consequence'] ?? '');
            $action      = htmlspecialchars($gap['recommended_action'] ?? '');
            $priority    = strtoupper($gap['priority'] ?? '');
            $statusColor = $status === 'ABSENT' ? '#c0393b' : ($status === 'WEAK' ? '#b07d30' : '#2d8a6e');
            $tierColor   = $tier === 'T1' ? '#4a6fa5' : ($tier === 'T2' ? '#2d8a6e' : '#b07d30');
            $cgmHtml    .= "
            <tr>
                <td><span class='tier-badge' style='background:{$tierColor}'>{$tier}</span></td>
                <td><strong>{$source}</strong></td>
                <td><span style='color:{$statusColor};font-weight:600'>{$status}</span></td>
                <td>{$consequence}</td>
                <td>{$action}</td>
                <td><span class='priority-tag'>{$priority}</span></td>
            </tr>";
        }

        // Section 4 — PSOS
        $psos         = $data['psos_intervention_priority'] ?? [];
        $psosBand     = htmlspecialchars($psos['overall_band'] ?? 'Not tested');
        $psosWeak     = htmlspecialchars($psos['weakest_dimension'] ?? '—');
        $psosFinding  = htmlspecialchars($psos['fragility_finding'] ?? '');
        $psosIntvHtml = '';
        foreach ($psos['priority_interventions'] ?? [] as $pi) {
            $dim            = htmlspecialchars($pi['dimension'] ?? '');
            $intv           = htmlspecialchars($pi['intervention'] ?? '');
            $rat            = htmlspecialchars($pi['rationale'] ?? '');
            $psosIntvHtml  .= "<div class='psos-item'><strong>{$dim}:</strong> {$intv}<br><span class='rationale'>{$rat}</span></div>";
        }
        $psosBandColor = $psosBand === 'Fragile' ? '#c0393b' : ($psosBand === 'Moderate' ? '#b07d30' : '#2d8a6e');

        // Section 5 — Sequenced Programme
        $seqHtml = '';
        foreach ($data['sequenced_programme'] ?? [] as $phase) {
            $phaseNum = (int)($phase['phase'] ?? 0);
            $label    = htmlspecialchars($phase['label'] ?? '');
            $timeline = htmlspecialchars($phase['timeline'] ?? '');
            $focus    = htmlspecialchars($phase['t1_t2_t3_focus'] ?? '');
            $dep      = htmlspecialchars($phase['dependency'] ?? '');
            $metric   = htmlspecialchars($phase['expected_metric_change'] ?? '');
            $actions  = implode('', array_map(fn($a) => '<li>' . htmlspecialchars($a) . '</li>', $phase['actions'] ?? []));
            $seqHtml .= "
            <div class='phase-block'>
                <div class='phase-header'>
                    <span class='phase-num'>{$phaseNum}</span>
                    <div>
                        <div class='phase-label'>{$label}</div>
                        <div class='phase-timeline'>{$timeline}</div>
                    </div>
                    <span class='focus-tag'>{$focus}</span>
                </div>
                <ul class='phase-actions'>{$actions}</ul>
                " . ($dep    ? "<div class='phase-dep'><strong>Enables:</strong> {$dep}</div>"         : '') . "
                " . ($metric ? "<div class='phase-metric'><strong>Expected:</strong> {$metric}</div>" : '') . "
            </div>";
        }

        // Section 6 — Reaudit Schedule
        $rs       = $data['reaudit_schedule'] ?? [];
        $w4       = htmlspecialchars($rs['week_4_checkpoint'] ?? '');
        $w8       = htmlspecialchars($rs['week_8_checkpoint'] ?? '');
        $q1       = htmlspecialchars($rs['quarter_1_full_audit'] ?? '');
        $compMon  = htmlspecialchars($rs['competitive_monitoring_priority'] ?? '');
        $triggers = implode('', array_map(fn($t) => '<li>' . htmlspecialchars($t) . '</li>', $rs['early_warning_triggers'] ?? []));

        // Pre-compute conditional blocks (cannot use concatenation inside heredoc)
        $triggersHtml = $triggers ? "<div class='reaudit-label' style='margin-bottom:4px'>Early Warning Triggers</div><ul class='triggers'>{$triggers}</ul>" : '';
        $compMonHtml  = $compMon  ? "<div class='reaudit-item' style='margin-top:10px'><div class='reaudit-label'>Competitive Monitoring</div><div class='reaudit-text'>{$compMon}</div></div>" : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a2533; background: #fff; line-height: 1.5; }

  .cover { padding: 50px 50px 40px; border-bottom: 3px solid #1a2533; margin-bottom: 30px; }
  .cover-agency { font-size: 9px; letter-spacing: .12em; text-transform: uppercase; color: #6b8299; margin-bottom: 20px; }
  .cover-title { font-size: 22px; font-weight: 700; color: #1a2533; margin-bottom: 6px; }
  .cover-sub { font-size: 13px; color: #4a6b8a; margin-bottom: 20px; }
  .cover-meta { display: flex; gap: 30px; }
  .meta-item { }
  .meta-label { font-size: 8px; letter-spacing: .1em; text-transform: uppercase; color: #8aa0b8; margin-bottom: 2px; }
  .meta-value { font-size: 12px; font-weight: 600; color: #1a2533; }

  .content { padding: 0 50px 50px; }

  .section { margin-bottom: 28px; page-break-inside: avoid; }
  .section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e8ede6; }
  .section-num { width: 22px; height: 22px; background: #1a2533; color: #fff; border-radius: 50%; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .section-title { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #1a2533; }

  .verdict-headline { font-size: 13px; font-weight: 600; color: #1a2533; margin-bottom: 8px; line-height: 1.4; }
  .verdict-summary { font-size: 10px; color: #3a5068; margin-bottom: 10px; line-height: 1.6; }
  .verdict-badges { display: flex; gap: 8px; margin-bottom: 8px; }
  .severity-badge { padding: 3px 10px; border-radius: 12px; font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
  .verdict-badge { padding: 3px 10px; border-radius: 12px; font-size: 9px; font-weight: 600; background: #eef3f8; color: #3a5068; }
  .rar-context { font-size: 9px; color: #6b8299; font-style: italic; padding: 8px 12px; background: #f8f4ee; border-left: 3px solid #b07d30; }

  .plat-block { margin-bottom: 10px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e4eaf0; border-radius: 4px; page-break-inside: avoid; }
  .plat-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
  .plat-name { font-size: 11px; font-weight: 700; color: #1a2533; }
  .dit-badge { font-size: 9px; font-weight: 700; padding: 2px 7px; background: #fff; border: 1px solid currentColor; border-radius: 3px; }
  .mechanism-tag { font-size: 8px; color: #6b8299; background: #eef3f8; padding: 2px 7px; border-radius: 3px; }
  .competitors { font-size: 9px; color: #c0393b; margin-bottom: 4px; }
  .diagnosis { font-size: 9px; color: #3a5068; margin-bottom: 4px; line-height: 1.5; }
  .intervention { font-size: 9px; color: #2d8a6e; padding: 4px 8px; background: rgba(45,138,110,.06); border-radius: 3px; }

  .cgm-table { width: 100%; border-collapse: collapse; font-size: 9px; }
  .cgm-table th { background: #f0f4f8; padding: 6px 8px; text-align: left; font-size: 8px; letter-spacing: .06em; text-transform: uppercase; color: #6b8299; font-weight: 600; border-bottom: 1px solid #dce4ec; }
  .cgm-table td { padding: 7px 8px; border-bottom: 1px solid #eef2f6; vertical-align: top; }
  .tier-badge { color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 8px; font-weight: 700; }
  .priority-tag { font-size: 8px; font-weight: 600; color: #6b8299; }

  .psos-band { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-bottom: 8px; }
  .psos-finding { font-size: 9px; color: #3a5068; margin-bottom: 10px; line-height: 1.6; padding: 8px 12px; background: #f8fafc; border-radius: 4px; }
  .psos-item { margin-bottom: 8px; font-size: 9px; padding: 6px 10px; background: #f8f4ee; border-radius: 3px; line-height: 1.5; }
  .rationale { color: #6b8299; font-style: italic; }

  .phase-block { margin-bottom: 10px; padding: 10px 12px; border: 1px solid #e4eaf0; border-radius: 4px; page-break-inside: avoid; }
  .phase-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
  .phase-num { width: 24px; height: 24px; background: #1a2533; color: #fff; border-radius: 50%; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .phase-label { font-size: 11px; font-weight: 600; color: #1a2533; }
  .phase-timeline { font-size: 9px; color: #6b8299; }
  .focus-tag { margin-left: auto; font-size: 8px; color: #4a6b8a; background: #eef3f8; padding: 2px 8px; border-radius: 3px; white-space: nowrap; }
  .phase-actions { margin-left: 34px; margin-bottom: 6px; font-size: 9px; color: #3a5068; }
  .phase-actions li { margin-bottom: 3px; line-height: 1.5; }
  .phase-dep { font-size: 9px; color: #2d8a6e; margin-left: 34px; margin-bottom: 3px; }
  .phase-metric { font-size: 9px; color: #b07d30; margin-left: 34px; }

  .reaudit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
  .reaudit-item { padding: 8px 10px; background: #f8fafc; border: 1px solid #e4eaf0; border-radius: 4px; }
  .reaudit-label { font-size: 8px; letter-spacing: .08em; text-transform: uppercase; color: #8aa0b8; font-weight: 600; margin-bottom: 4px; }
  .reaudit-text { font-size: 9px; color: #3a5068; line-height: 1.5; }
  .triggers { font-size: 9px; color: #c0393b; padding-left: 14px; }
  .triggers li { margin-bottom: 3px; }

  .footer { margin-top: 30px; padding: 15px 50px; border-top: 1px solid #e4eaf0; display: flex; justify-content: space-between; font-size: 8px; color: #8aa0b8; }

  @page { margin: 15mm 0; }
</style>
</head>
<body>

<div class="cover">
  <div class="cover-agency">{$agencyName} · AIVO Meridian Platform</div>
  <div class="cover-title">LLM Ad Readiness Report</div>
  <div class="cover-sub">{$brandName} · {$category}</div>
  <div class="cover-meta">
    <div class="meta-item">
      <div class="meta-label">Reasoning Chain Score</div>
      <div class="meta-value">{$rcsDisplay}</div>
    </div>
    <div class="meta-item">
      <div class="meta-label">Ad Readiness Verdict</div>
      <div class="meta-value" style="color:{$verdictColor}">{$verdictLabel}</div>
    </div>
    <div class="meta-item">
      <div class="meta-label">Report Generated</div>
      <div class="meta-value">{$genDate}</div>
    </div>
  </div>
</div>

<div class="content">

  <div class="section">
    <div class="section-header">
      <div class="section-num">1</div>
      <div class="section-title">Diagnostic Verdict</div>
    </div>
    <div class="verdict-headline">{$headline}</div>
    <div class="verdict-summary">{$summary}</div>
    <div class="verdict-badges">
      <span class="severity-badge" style="background:{$sevColor};color:#fff">{$severity}</span>
      <span class="verdict-badge">{$verdictLabel}</span>
    </div>
    <div class="rar-context">{$rarContext}</div>
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-num">2</div>
      <div class="section-title">Platform Displacement Analysis</div>
    </div>
    {$platHtml}
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-num">3</div>
      <div class="section-title">Citation Architecture Gap Matrix</div>
    </div>
    <table class="cgm-table">
      <thead>
        <tr>
          <th>Tier</th><th>Source Category</th><th>Status</th>
          <th>Commercial Consequence</th><th>Recommended Action</th><th>Priority</th>
        </tr>
      </thead>
      <tbody>{$cgmHtml}</tbody>
    </table>
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-num">4</div>
      <div class="section-title">PSOS-Routed Intervention Priority</div>
    </div>
    <span class="psos-band" style="color:{$psosBandColor};background:rgba(0,0,0,.06)">{$psosBand} — Weakest: {$psosWeak}</span>
    <div class="psos-finding">{$psosFinding}</div>
    {$psosIntvHtml}
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-num">5</div>
      <div class="section-title">Sequenced Remediation Programme</div>
    </div>
    {$seqHtml}
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-num">6</div>
      <div class="section-title">Reaudit Schedule</div>
    </div>
    <div class="reaudit-grid">
      <div class="reaudit-item">
        <div class="reaudit-label">Week 4 Checkpoint</div>
        <div class="reaudit-text">{$w4}</div>
      </div>
      <div class="reaudit-item">
        <div class="reaudit-label">Week 8 Checkpoint</div>
        <div class="reaudit-text">{$w8}</div>
      </div>
      <div class="reaudit-item" style="grid-column:span 2">
        <div class="reaudit-label">Q1 Full Audit</div>
        <div class="reaudit-text">{$q1}</div>
      </div>
    </div>
    {$triggersHtml}
    {$compMonHtml}
  </div>

</div>

<div class="footer">
  <span>{$agencyName} · AIVO Meridian Platform · Confidential</span>
  <span>Generated {$genDate}</span>
</div>

</body>
</html>
HTML;
    }

    private function verdictLabel(?string $verdict): string
    {
        return match($verdict) {
            'amplification_ready'    => 'Amplification Ready',
            'advertise_with_caution' => 'Advertise with Caution',
            'do_not_advertise'       => 'Resolve Displacement First',
            'monitor'                => 'Monitor Closely',
            default                  => $verdict ?? '—',
        };
    }

    private function verdictColor(?string $verdict): string
    {
        return match($verdict) {
            'amplification_ready'    => '#2d8a6e',
            'advertise_with_caution' => '#b07d30',
            'do_not_advertise'       => '#c0393b',
            default                  => '#4a6b8a',
        };
    }
}
