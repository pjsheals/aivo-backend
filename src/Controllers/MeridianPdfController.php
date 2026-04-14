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

        $agency = DB::table('meridian_agencies')
            ->where('id', $agencyId)
            ->first(['name', 'logo_url']);

        $html = $this->buildHtml($brand, $agency, $data, $rcs, $verdict, $genAt);

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
        $verdictBg    = $this->verdictBg($verdict);

        // Section 1 — Diagnostic Verdict
        $dv         = $data['diagnostic_verdict'] ?? [];
        $headline   = htmlspecialchars($dv['headline'] ?? '');
        $summary    = htmlspecialchars($dv['summary'] ?? '');
        $severity   = strtoupper($dv['severity'] ?? '');
        $rarContext = htmlspecialchars($dv['rar_context'] ?? '');
        $sevColor   = in_array($dv['severity'] ?? '', ['critical','high']) ? '#c0393b' : ($dv['severity'] === 'moderate' ? '#b07d30' : '#2d8a6e');
        $sevBg      = in_array($dv['severity'] ?? '', ['critical','high']) ? '#fdf0f0' : ($dv['severity'] === 'moderate' ? '#fdf6ec' : '#edf7f3');

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
            $ditBg        = in_array($p['dit'] ?? '', ['T1','T2']) ? '#fdf0f0' : ($p['dit'] === 'null' ? '#edf7f3' : '#fdf6ec');
            $compRow      = $competitors
                ? "<tr><td colspan='2' style='padding-bottom:5px'><span style='font-size:9px;color:#c0393b'><strong>Displacing:</strong> {$competitors}</span></td></tr>"
                : '';
            $platHtml    .= "
            <div class='plat-block'>
                <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td style='padding-bottom:6px;vertical-align:middle'>
                            <span class='plat-name'>{$label}</span>
                            &nbsp;&nbsp;
                            <span class='dit-badge' style='color:{$ditColor};background:{$ditBg};border:1px solid {$ditColor}'>DIT {$dit}</span>
                            &nbsp;
                            <span class='mechanism-tag'>{$mechanism}</span>
                        </td>
                    </tr>
                    {$compRow}
                </table>
                <p class='diagnosis'>{$diagnosis}</p>
                <div class='intervention'>&#10003;&nbsp; <strong>Intervention:</strong> {$intervention}</div>
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
            $statusBg    = $status === 'ABSENT' ? '#fdf0f0' : ($status === 'WEAK' ? '#fdf6ec' : '#edf7f3');
            $tierBg      = $tier === 'T1' ? '#4a6fa5' : ($tier === 'T2' ? '#2d8a6e' : '#b07d30');
            $priColor    = $priority === 'IMMEDIATE' ? '#c0393b' : ($priority === '30-DAY' ? '#b07d30' : '#4a6b8a');
            $cgmHtml    .= "
            <tr>
                <td style='padding:7px 8px;border-bottom:1px solid #eef2f6;vertical-align:top;width:5%'>
                    <span style='background:{$tierBg};color:#fff;padding:2px 7px;border-radius:3px;font-size:8px;font-weight:700'>{$tier}</span>
                </td>
                <td style='padding:7px 8px;border-bottom:1px solid #eef2f6;vertical-align:top;width:18%'>
                    <strong style='font-size:9px'>{$source}</strong>
                </td>
                <td style='padding:7px 8px;border-bottom:1px solid #eef2f6;vertical-align:top;width:10%'>
                    <span style='color:{$statusColor};background:{$statusBg};padding:2px 7px;border-radius:3px;font-size:8px;font-weight:700'>{$status}</span>
                </td>
                <td style='padding:7px 8px;border-bottom:1px solid #eef2f6;vertical-align:top;font-size:8.5px;color:#3a5068;width:27%'>{$consequence}</td>
                <td style='padding:7px 8px;border-bottom:1px solid #eef2f6;vertical-align:top;font-size:8.5px;color:#3a5068;width:27%'>{$action}</td>
                <td style='padding:7px 8px;border-bottom:1px solid #eef2f6;vertical-align:top;width:13%'>
                    <span style='color:{$priColor};font-size:8px;font-weight:700'>{$priority}</span>
                </td>
            </tr>";
        }

        // Section 4 — PSOS
        $psos         = $data['psos_intervention_priority'] ?? [];
        $psosBand     = htmlspecialchars($psos['overall_band'] ?? 'Not tested');
        $psosWeak     = htmlspecialchars($psos['weakest_dimension'] ?? '—');
        $psosFinding  = htmlspecialchars($psos['fragility_finding'] ?? '');
        $psosIntvHtml = '';
        foreach ($psos['priority_interventions'] ?? [] as $pi) {
            $dim           = htmlspecialchars($pi['dimension'] ?? '');
            $intv          = htmlspecialchars($pi['intervention'] ?? '');
            $rat           = htmlspecialchars($pi['rationale'] ?? '');
            $psosIntvHtml .= "
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:7px'>
                <tr>
                    <td style='width:4px;background:#1a2533;border-radius:2px 0 0 2px'>&nbsp;</td>
                    <td style='padding:7px 10px;background:#f8fafc;border:1px solid #dce6f0;border-left:none;font-size:9px;line-height:1.6'>
                        <strong style='color:#1a2533'>{$dim}:</strong>&nbsp;<span style='color:#3a5068'>{$intv}</span><br>
                        <span style='color:#8aa0b8;font-style:italic'>{$rat}</span>
                    </td>
                </tr>
            </table>";
        }
        $psosBandColor = $psosBand === 'Fragile' ? '#c0393b' : ($psosBand === 'Moderate' ? '#8a5c1a' : '#1a6b4e');
        $psosBandBg    = $psosBand === 'Fragile' ? '#fdf0f0' : ($psosBand === 'Moderate' ? '#fdf6ec' : '#edf7f3');

        // Section 5 — Sequenced Programme
        $seqHtml     = '';
        $phaseColors = ['#4a6fa5', '#2d8a6e', '#b07d30', '#7b5ea7'];
        foreach ($data['sequenced_programme'] ?? [] as $idx => $phase) {
            $phaseNum   = (int)($phase['phase'] ?? 0);
            $label      = htmlspecialchars($phase['label'] ?? '');
            $timeline   = htmlspecialchars($phase['timeline'] ?? '');
            $focus      = htmlspecialchars($phase['t1_t2_t3_focus'] ?? '');
            $dep        = htmlspecialchars($phase['dependency'] ?? '');
            $metric     = htmlspecialchars($phase['expected_metric_change'] ?? '');
            $actions    = implode('', array_map(fn($a) => '<li style="margin-bottom:3px;line-height:1.5">' . htmlspecialchars($a) . '</li>', $phase['actions'] ?? []));
            $pColor     = $phaseColors[$idx % count($phaseColors)];
            $depHtml    = $dep    ? "<div style='margin-top:6px;font-size:8.5px;color:#1a6b4e'><strong>&#8594; Enables:</strong> {$dep}</div>" : '';
            $metricHtml = $metric ? "<div style='margin-top:4px;font-size:8.5px;color:{$pColor}'><strong>&#8599; Expected:</strong> {$metric}</div>" : '';
            $seqHtml   .= "
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:10px;border:1px solid #dce6f0;border-radius:4px'>
                <tr>
                    <td style='width:36px;background:{$pColor};border-radius:4px 0 0 4px;text-align:center;vertical-align:top;padding:14px 0'>
                        <span style='color:#fff;font-size:15px;font-weight:700;display:block;line-height:1'>{$phaseNum}</span>
                        <span style='color:rgba(255,255,255,0.65);font-size:6.5px;display:block;margin-top:3px;letter-spacing:.1em;text-transform:uppercase'>Phase</span>
                    </td>
                    <td style='padding:10px 14px;vertical-align:top'>
                        <table width='100%' cellpadding='0' cellspacing='0'>
                            <tr>
                                <td style='vertical-align:top'>
                                    <div style='font-size:11px;font-weight:700;color:#1a2533'>{$label}</div>
                                    <div style='font-size:8.5px;color:#8aa0b8;margin-top:2px'>{$timeline}</div>
                                </td>
                                <td style='text-align:right;vertical-align:top;padding-left:10px'>
                                    <span style='font-size:8px;color:{$pColor};background:rgba(0,0,0,0.05);padding:2px 9px;border-radius:3px;white-space:nowrap'>{$focus}</span>
                                </td>
                            </tr>
                        </table>
                        <ul style='margin:8px 0 0 14px;font-size:9px;color:#3a5068'>{$actions}</ul>
                        {$depHtml}
                        {$metricHtml}
                    </td>
                </tr>
            </table>";
        }

        // Section 6 — Reaudit Schedule
        $rs       = $data['reaudit_schedule'] ?? [];
        $w4       = htmlspecialchars($rs['week_4_checkpoint'] ?? '');
        $w8       = htmlspecialchars($rs['week_8_checkpoint'] ?? '');
        $q1       = htmlspecialchars($rs['quarter_1_full_audit'] ?? '');
        $compMon  = htmlspecialchars($rs['competitive_monitoring_priority'] ?? '');
        $triggers = implode('', array_map(
            fn($t) => '<li style="margin-bottom:3px;color:#c0393b">' . htmlspecialchars($t) . '</li>',
            $rs['early_warning_triggers'] ?? []
        ));

        $triggersHtml = $triggers
            ? "<div style='margin-top:12px'><div class='ra-label'>Early Warning Triggers</div><ul style='margin:6px 0 0 14px;font-size:9px;padding:0'>{$triggers}</ul></div>"
            : '';
        $compMonHtml  = $compMon
            ? "<div style='margin-top:10px;padding:8px 10px;background:#f8fafc;border:1px solid #dce6f0;border-radius:4px'><div class='ra-label'>Competitive Monitoring</div><div style='font-size:9px;color:#3a5068;margin-top:3px'>{$compMon}</div></div>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a2533; background: #fff; line-height: 1.5; }

  .cover-bar  { background: #1a2533; padding: 28px 44px 22px; }
  .cover-body { padding: 16px 44px 20px; border-bottom: 3px solid #1a2533; margin-bottom: 0; }
  .cover-eyebrow { font-size: 8px; letter-spacing: .15em; text-transform: uppercase; color: rgba(255,255,255,0.5); margin-bottom: 10px; }
  .cover-title  { font-size: 24px; font-weight: 700; color: #fff; margin-bottom: 5px; letter-spacing: -.3px; }
  .cover-sub    { font-size: 13px; color: rgba(255,255,255,0.6); }

  .content { padding: 24px 44px 44px; }

  .sec-wrap   { margin-bottom: 24px; page-break-inside: avoid; }
  .sec-eyebrow { font-size: 7.5px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #8aa0b8; margin-bottom: 3px; }
  .sec-title  { font-size: 13px; font-weight: 700; color: #1a2533; padding-bottom: 7px; border-bottom: 2px solid #1a2533; margin-bottom: 12px; }

  .verdict-headline { font-size: 12px; font-weight: 600; color: #1a2533; margin-bottom: 8px; line-height: 1.5; }
  .verdict-summary  { font-size: 9.5px; color: #3a5068; margin-bottom: 12px; line-height: 1.7; }
  .rar-context { font-size: 9px; color: #6b5c2e; font-style: italic; padding: 8px 12px; background: #fdf6ec; border-left: 3px solid #b07d30; margin-top: 10px; line-height: 1.6; }

  .plat-block  { margin-bottom: 9px; padding: 10px 12px; background: #f8fafc; border: 1px solid #dce6f0; border-radius: 4px; page-break-inside: avoid; }
  .plat-name   { font-size: 11px; font-weight: 700; color: #1a2533; }
  .dit-badge   { font-size: 8.5px; font-weight: 700; padding: 2px 8px; border-radius: 3px; }
  .mechanism-tag { font-size: 8px; color: #6b8299; background: #eef3f8; padding: 2px 8px; border-radius: 3px; }
  .diagnosis   { font-size: 9px; color: #3a5068; margin-bottom: 6px; line-height: 1.6; }
  .intervention { font-size: 9px; color: #1a6b4e; padding: 5px 9px; background: #edf7f3; border-left: 3px solid #2d8a6e; line-height: 1.6; }

  .ra-cell  { padding: 8px 10px; background: #f8fafc; border: 1px solid #dce6f0; border-radius: 4px; }
  .ra-label { font-size: 7.5px; letter-spacing: .1em; text-transform: uppercase; color: #8aa0b8; font-weight: 700; margin-bottom: 4px; }
  .ra-text  { font-size: 9px; color: #3a5068; line-height: 1.6; }

  .footer { margin-top: 20px; padding: 12px 44px; border-top: 1px solid #dce6f0; }

  @page { margin: 12mm 0; }
</style>
</head>
<body>

<!-- COVER -->
<div class="cover-bar">
  <div class="cover-eyebrow">{$agencyName} &nbsp;·&nbsp; AIVO Meridian Platform</div>
  <div class="cover-title">LLM Ad Readiness Report</div>
  <div class="cover-sub">{$brandName} &nbsp;·&nbsp; {$category}</div>
</div>
<div class="cover-body">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td style="width:33%;padding:12px 16px 8px 0;border-right:1px solid #e4eaf0;vertical-align:top">
        <div style="font-size:7.5px;letter-spacing:.1em;text-transform:uppercase;color:#8aa0b8;margin-bottom:3px">Reasoning Chain Score</div>
        <div style="font-size:15px;font-weight:700;color:#1a2533">{$rcsDisplay}</div>
      </td>
      <td style="width:34%;padding:12px 16px 8px;vertical-align:top">
        <div style="font-size:7.5px;letter-spacing:.1em;text-transform:uppercase;color:#8aa0b8;margin-bottom:3px">Ad Readiness Verdict</div>
        <div style="font-size:15px;font-weight:700;color:{$verdictColor}">{$verdictLabel}</div>
      </td>
      <td style="width:33%;padding:12px 0 8px 16px;border-left:1px solid #e4eaf0;text-align:right;vertical-align:top">
        <div style="font-size:7.5px;letter-spacing:.1em;text-transform:uppercase;color:#8aa0b8;margin-bottom:3px">Report Generated</div>
        <div style="font-size:13px;font-weight:600;color:#1a2533">{$genDate}</div>
      </td>
    </tr>
  </table>
</div>

<div class="content">

  <!-- SECTION 1 -->
  <div class="sec-wrap">
    <div class="sec-eyebrow">Section 01</div>
    <div class="sec-title">Diagnostic Verdict</div>
    <div class="verdict-headline">{$headline}</div>
    <div class="verdict-summary">{$summary}</div>
    <table cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding-right:8px">
          <span style="display:inline-block;padding:4px 13px;border-radius:3px;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:{$sevColor};color:#fff">{$severity}</span>
        </td>
        <td>
          <span style="display:inline-block;padding:4px 13px;border-radius:3px;font-size:9px;font-weight:600;background:{$verdictBg};color:{$verdictColor}">{$verdictLabel}</span>
        </td>
      </tr>
    </table>
    <div class="rar-context">{$rarContext}</div>
  </div>

  <!-- SECTION 2 -->
  <div class="sec-wrap">
    <div class="sec-eyebrow">Section 02</div>
    <div class="sec-title">Platform Displacement Analysis</div>
    {$platHtml}
  </div>

  <!-- SECTION 3 -->
  <div class="sec-wrap">
    <div class="sec-eyebrow">Section 03</div>
    <div class="sec-title">Citation Architecture Gap Matrix</div>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:9px;border:1px solid #dce6f0">
      <thead>
        <tr style="background:#f0f4f8">
          <th style="padding:7px 8px;text-align:left;font-size:7.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b8299;font-weight:700;border-bottom:2px solid #c8d4e0;width:5%">Tier</th>
          <th style="padding:7px 8px;text-align:left;font-size:7.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b8299;font-weight:700;border-bottom:2px solid #c8d4e0;width:18%">Source</th>
          <th style="padding:7px 8px;text-align:left;font-size:7.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b8299;font-weight:700;border-bottom:2px solid #c8d4e0;width:10%">Status</th>
          <th style="padding:7px 8px;text-align:left;font-size:7.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b8299;font-weight:700;border-bottom:2px solid #c8d4e0;width:27%">Commercial Consequence</th>
          <th style="padding:7px 8px;text-align:left;font-size:7.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b8299;font-weight:700;border-bottom:2px solid #c8d4e0;width:27%">Recommended Action</th>
          <th style="padding:7px 8px;text-align:left;font-size:7.5px;letter-spacing:.08em;text-transform:uppercase;color:#6b8299;font-weight:700;border-bottom:2px solid #c8d4e0;width:13%">Priority</th>
        </tr>
      </thead>
      <tbody>{$cgmHtml}</tbody>
    </table>
  </div>

  <!-- SECTION 4 -->
  <div class="sec-wrap">
    <div class="sec-eyebrow">Section 04</div>
    <div class="sec-title">PSOS-Routed Intervention Priority</div>
    <table cellpadding="0" cellspacing="0" style="margin-bottom:10px">
      <tr>
        <td style="padding-right:12px">
          <span style="display:inline-block;padding:5px 14px;border-radius:3px;font-size:11px;font-weight:700;background:{$psosBandBg};color:{$psosBandColor}">{$psosBand}</span>
        </td>
        <td>
          <span style="font-size:9px;color:#6b8299">Weakest dimension:&nbsp;<strong style="color:#1a2533">{$psosWeak}</strong></span>
        </td>
      </tr>
    </table>
    <div style="font-size:9px;color:#3a5068;margin-bottom:12px;line-height:1.7;padding:10px 12px;background:#f8fafc;border:1px solid #dce6f0;border-radius:4px">{$psosFinding}</div>
    {$psosIntvHtml}
  </div>

  <!-- SECTION 5 -->
  <div class="sec-wrap">
    <div class="sec-eyebrow">Section 05</div>
    <div class="sec-title">Sequenced Remediation Programme</div>
    {$seqHtml}
  </div>

  <!-- SECTION 6 -->
  <div class="sec-wrap">
    <div class="sec-eyebrow">Section 06</div>
    <div class="sec-title">Reaudit Schedule</div>
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td width="48%" style="vertical-align:top">
          <div class="ra-cell">
            <div class="ra-label">Week 4 Checkpoint</div>
            <div class="ra-text">{$w4}</div>
          </div>
        </td>
        <td width="4%">&nbsp;</td>
        <td width="48%" style="vertical-align:top">
          <div class="ra-cell">
            <div class="ra-label">Week 8 Checkpoint</div>
            <div class="ra-text">{$w8}</div>
          </div>
        </td>
      </tr>
      <tr>
        <td colspan="3" style="padding-top:8px;vertical-align:top">
          <div class="ra-cell">
            <div class="ra-label">Q1 Full Audit</div>
            <div class="ra-text">{$q1}</div>
          </div>
        </td>
      </tr>
    </table>
    {$triggersHtml}
    {$compMonHtml}
  </div>

</div>

<!-- FOOTER -->
<div class="footer">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td style="font-size:8px;color:#8aa0b8">{$agencyName} &nbsp;·&nbsp; AIVO Meridian Platform &nbsp;·&nbsp; Confidential</td>
      <td style="font-size:8px;color:#8aa0b8;text-align:right">Generated {$genDate}</td>
    </tr>
  </table>
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
            'amplification_ready'    => '#1a6b4e',
            'advertise_with_caution' => '#8a5c1a',
            'do_not_advertise'       => '#c0393b',
            default                  => '#3a5068',
        };
    }

    private function verdictBg(?string $verdict): string
    {
        return match($verdict) {
            'amplification_ready'    => '#edf7f3',
            'advertise_with_caution' => '#fdf6ec',
            'do_not_advertise'       => '#fdf0f0',
            default                  => '#eef3f8',
        };
    }
}
