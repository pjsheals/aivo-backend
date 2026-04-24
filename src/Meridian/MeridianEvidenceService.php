<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianEvidenceService — Module 3
 *
 * Handles evidence submission, verification, and authority scoring.
 *
 * Source authority hierarchy:
 *   Tier 1 (score 4): peer_reviewed, regulatory, clinical
 *   Tier 2 (score 3): independent dermatologist/expert, named lab study, third-party audit
 *   Tier 3 (score 2): press (Guardian, FT, Vogue, industry titles), review platforms
 *   Tier 4 (score 1): corporate, self_published
 */
class MeridianEvidenceService
{
    private const AUTHORITY_SCORES = [
        // Tier 1 — Maximum authority
        'peer_reviewed'   => 4,
        'regulatory'      => 4,
        'clinical'        => 4,
        // Tier 2 — High authority
        'expert'          => 3,
        'third_party'     => 3,
        'analyst_report'  => 3,
        'industry_award'  => 3,
        // Tier 3 — Medium authority
        'press'           => 2,
        'review_platform' => 2,
        'case_study'      => 2,
        'video'           => 2,
        // Tier 4 — Supporting evidence
        'consumer_review' => 1,
        'corporate'       => 1,
        'social_proof'    => 1,
        'self_published'  => 1,
    ];

    private const TIER1_DOMAINS = [
        // Academic & regulatory (pharma/regulated industries)
        'pubmed.ncbi.nlm.nih.gov', 'ncbi.nlm.nih.gov',
        'doi.org', 'zenodo.org',
        'fda.gov', 'ema.europa.eu', 'mhra.gov.uk',
        'clinicaltrials.gov',
        'journals.plos.org', 'nature.com', 'sciencedirect.com',
        'jamanetwork.com', 'bmj.com', 'thelancet.com',
        // Technology & SaaS authority sources
        'g2.com', 'capterra.com', 'gartner.com', 'forrester.com',
        'techcrunch.com', 'venturebeat.com', 'producthunt.com',
        'wired.com', 'theverge.com', 'technologyreview.mit.edu',
        // Business & marketing authority
        'forbes.com', 'hbr.org', 'mckinsey.com',
        'marketingland.com', 'searchengineland.com', 'searchenginejournal.com',
        // General press (high authority)
        'ft.com', 'bloomberg.com', 'reuters.com', 'wsj.com',
        'theguardian.com', 'bbc.co.uk', 'economist.com',
    ];

    private const PROBE_TYPE_LABELS = [
        'awareness'                  => 'Awareness',
        'decision_stage'             => 'Decision-Stage',
        'spontaneous_consideration'  => 'Spontaneous Consideration',
    ];

    // -------------------------------------------------------------------------
    // Submit evidence
    // -------------------------------------------------------------------------

    public function submit(array $data, int $agencyId, int $userId): array
    {
        $this->validateSubmission($data);

        $authorityScore = $this->computeAuthorityScore($data['source_type'] ?? null);

        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        DB::table('meridian_evidence_submissions')->insert([
            'id'                  => $id,
            'brand_id'            => (int)$data['brand_id'],
            'audit_id'            => isset($data['audit_id']) ? (int)$data['audit_id'] : null,
            'agency_id'           => $agencyId,
            'filter_type'         => $data['filter_type'],
            'field_name'          => $data['field_name']    ?? null,
            'source_type'         => $data['source_type']   ?? null,
            'source_url'          => $data['source_url']    ?? null,
            'source_title'        => $data['source_title']  ?? null,
            'doi'                 => $data['doi']            ?? null,
            'free_text'           => $data['free_text']     ?? null,
            'date_published'      => $data['date_published'] ?? null,
            'authority_score'     => $authorityScore,
            'verification_status' => 'pending',
            'submitted_by'        => $userId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Run URL/DOI reachability check inline so the row lands in a final
        // state (verified | failed | invalid) instead of sitting as 'pending'
        // forever. Worst-case adds ~10s per submit if the URL times out.
        // Wrapped in try/catch so a verify failure NEVER blocks submission —
        // the row still exists, user can re-verify or edit later.
        $verifyResult = [
            'submission_id'       => $id,
            'verified'            => false,
            'verification_status' => 'pending',
            'url_resolves'        => null,
            'doi_resolves'        => null,
            'notes'               => [],
        ];

        try {
            $verifyResult = $this->verify($id);
        } catch (\Throwable $e) {
            error_log('[MeridianEvidence] post-submit verify failed for ' . $id . ': ' . $e->getMessage());
            $verifyResult['notes'] = ['Verification could not be performed — will retry on next action.'];
        }

        return [
            'submission_id'       => $id,
            'authority_score'     => $authorityScore,
            'authority_tier'      => $this->getTierLabel($authorityScore),
            'verification_status' => $verifyResult['verification_status'] ?? 'pending',
            'verified'            => $verifyResult['verified']            ?? false,
            'url_resolves'        => $verifyResult['url_resolves']        ?? null,
            'doi_resolves'        => $verifyResult['doi_resolves']        ?? null,
            'verification_notes'  => $verifyResult['notes']               ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Verify a submission
    // -------------------------------------------------------------------------

    public function verify(string $submissionId): array
    {
        $submission = DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->first();

        if (!$submission) {
            throw new \RuntimeException("Submission {$submissionId} not found.");
        }

        $urlResolves = false;
        $doiResolves = false;
        $notes       = [];

        if ($submission->source_url) {
            $urlResolves = $this->checkUrlResolves($submission->source_url);
            if (!$urlResolves) $notes[] = 'URL did not return HTTP 200.';
        }

        if ($submission->doi) {
            $doiResolves = $this->checkDoiResolves($submission->doi);
            if (!$doiResolves) $notes[] = 'DOI did not resolve via doi.org.';
        }

        $hasContent = $submission->source_url || $submission->doi || $submission->free_text;
        $urlOk      = !$submission->source_url || $urlResolves;
        $doiOk      = !$submission->doi        || $doiResolves;
        $status     = ($hasContent && $urlOk && $doiOk) ? 'verified' : 'failed';

        if (!$hasContent) {
            $notes[] = 'No source URL, DOI, or free text provided.';
            $status  = 'invalid';
        }

        DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->update([
                'verification_status' => $status,
                'verification_notes'  => implode(' ', $notes) ?: null,
                'url_resolves'        => $urlResolves,
                'doi_resolves'        => $doiResolves,
                'updated_at'          => now(),
            ]);

        return [
            'submission_id'       => $submissionId,
            'verified'            => $status === 'verified',
            'verification_status' => $status,
            'url_resolves'        => $urlResolves,
            'doi_resolves'        => $doiResolves,
            'notes'               => $notes,
            'authority_score'     => $submission->authority_score,
        ];
    }

    // -------------------------------------------------------------------------
    // Update an existing submission
    //
    // Allows editing URL, type, title, DOI, free_text, or date_published on an
    // existing submission. Re-runs verification after the update since URL or
    // DOI may have changed. Scoped to agency for defence in depth.
    // -------------------------------------------------------------------------

    public function update(string $submissionId, array $data, int $agencyId): array
    {
        $submission = DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->where('agency_id', $agencyId)
            ->first();

        if (!$submission) {
            throw new \RuntimeException('Submission not found or access denied.');
        }

        // Compute the post-update state for content validation — use the new
        // value if provided, otherwise fall back to the existing value.
        $newUrl      = array_key_exists('source_url', $data) ? $data['source_url'] : $submission->source_url;
        $newDoi      = array_key_exists('doi',        $data) ? $data['doi']        : $submission->doi;
        $newFreeText = array_key_exists('free_text',  $data) ? $data['free_text']  : $submission->free_text;

        $hasContent = !empty($newUrl) || !empty($newDoi) || !empty($newFreeText);
        if (!$hasContent) {
            throw new \InvalidArgumentException('At least one of source_url, doi, or free_text is required.');
        }

        $updates = ['updated_at' => now()];

        if (array_key_exists('source_url',     $data)) $updates['source_url']     = $data['source_url']     ?: null;
        if (array_key_exists('source_type',    $data)) $updates['source_type']    = $data['source_type']    ?: null;
        if (array_key_exists('source_title',   $data)) $updates['source_title']   = $data['source_title']   ?: null;
        if (array_key_exists('doi',            $data)) $updates['doi']            = $data['doi']            ?: null;
        if (array_key_exists('free_text',      $data)) $updates['free_text']      = $data['free_text']      ?: null;
        if (array_key_exists('date_published', $data)) $updates['date_published'] = $data['date_published'] ?: null;

        // Recompute authority_score if source_type changed, since the tier
        // mapping could have shifted.
        if (array_key_exists('source_type', $data)) {
            $updates['authority_score'] = $this->computeAuthorityScore($data['source_type'] ?? null);
        }

        DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->update($updates);

        // Re-run verify since URL/DOI may have changed. If this throws we let
        // it propagate — unlike submit(), the row already existed in a valid
        // state before the update, so a verify failure on update is genuine.
        $verifyResult = $this->verify($submissionId);

        return array_merge($verifyResult, [
            'submission_id' => $submissionId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Get submissions for a brand/audit grouped by filter
    // -------------------------------------------------------------------------

    public function getByBrand(int $brandId, ?int $auditId = null): array
    {
        $query = DB::table('meridian_evidence_submissions')
            ->where('brand_id', $brandId)
            ->orderByDesc('created_at');

        if ($auditId) $query->where('audit_id', $auditId);

        $rows = $query->get();

        $grouped = [];
        foreach ($rows as $row) {
            $filter = $row->filter_type;
            if (!isset($grouped[$filter])) $grouped[$filter] = [];
            $grouped[$filter][] = [
                'id'                  => $row->id,
                'filter_type'         => $row->filter_type,
                'field_name'          => $row->field_name,
                'source_type'         => $row->source_type,
                'source_url'          => $row->source_url,
                'source_title'        => $row->source_title,
                'doi'                 => $row->doi,
                'free_text'           => $row->free_text,
                'date_published'      => $row->date_published,
                'authority_score'     => $row->authority_score,
                'authority_tier'      => $this->getTierLabel($row->authority_score),
                'verification_status' => $row->verification_status,
                'verification_notes'  => $row->verification_notes,
                'url_resolves'        => $row->url_resolves,
                'doi_resolves'        => $row->doi_resolves,
                'created_at'          => $row->created_at,
            ];
        }

        return $grouped;
    }

    // -------------------------------------------------------------------------
    // Get gap completion status
    //
    // Returns full displacement context per gap including mechanism_explanation
    // so the frontend can render the "Understanding this gap" section.
    // -------------------------------------------------------------------------

    public function getGapCompletionStatus(int $brandId, int $auditId): array
    {
        // Load all classifications for this audit
        $classifications = DB::table('meridian_filter_classifications')
            ->where('audit_id', $auditId)
            ->orderBy('created_at')
            ->get();

        if ($classifications->isEmpty()) {
            return [
                'gaps_total'   => 0,
                'gaps_ready'   => 0,
                'classified'   => false,
                'filters'      => [],
            ];
        }

        // Build gap map — one entry per filter, aggregating across platforms.
        // When classifyAll runs per platform × mode, multiple classifications
        // can exist for the same filter. We keep the most urgent (highest
        // survival_gap) and accumulate all affected platforms.
        $gapMap = [];

        foreach ($classifications as $c) {
            $gapData = json_decode($c->evidence_gaps ?? '[]', true);

            // Extract mechanism_explanation from DB — stored as JSON
            $mechanismExplanation = null;
            if (!empty($c->mechanism_explanation)) {
                $decoded = json_decode($c->mechanism_explanation, true);
                if (is_array($decoded)) {
                    $mechanismExplanation = $decoded;
                }
            }

            // Extract probe_mode from classifier_output metadata if available
            $probeMode = null;
            if (!empty($c->classifier_output)) {
                $co = json_decode($c->classifier_output, true);
                if (isset($co['_probe_mode'])) {
                    $probeMode = $co['_probe_mode'];
                }
            }

            foreach ($gapData as $gap) {
                $filter = $gap['filter'] ?? null;
                if (!$filter) continue;

                $key = $filter;

                if (!isset($gapMap[$key])) {
                    $gapMap[$key] = [
                        'filter'                 => $filter,
                        'gap_description'        => $gap['gap'] ?? '',
                        'probe_type'             => $c->probe_type ?? 'decision_stage',
                        'probe_type_label'       => self::PROBE_TYPE_LABELS[$c->probe_type ?? 'decision_stage'] ?? 'Decision-Stage',
                        'probe_mode'             => $probeMode,
                        'displacement_turn'      => $c->dit_turn     ? (int)$c->dit_turn     : null,
                        'handoff_turn'           => $c->handoff_turn ? (int)$c->handoff_turn : null,
                        'survival_gap'           => $c->survival_gap ? (int)$c->survival_gap : null,
                        'displacement_criteria'  => $c->displacement_criteria  ?? null,
                        'displacement_mechanism' => $c->displacement_mechanism ?? null,
                        'displacing_brand'       => $c->t4_winner ?? null,
                        'platforms_affected'     => [],
                        'primary_filter'         => $c->primary_filter ?? $filter,
                        'confidence'             => (int)($c->confidence_score ?? 0),
                        'mechanism_explanation'  => $mechanismExplanation,
                    ];
                }

                // Accumulate affected platforms
                if (!in_array($c->platform, $gapMap[$key]['platforms_affected'])) {
                    $gapMap[$key]['platforms_affected'][] = $c->platform;
                }

                // Use most urgent survival gap
                $existingGap = $gapMap[$key]['survival_gap'];
                $thisGap     = $c->survival_gap ? (int)$c->survival_gap : null;
                if ($thisGap !== null && ($existingGap === null || $thisGap > $existingGap)) {
                    $gapMap[$key]['survival_gap']           = $thisGap;
                    $gapMap[$key]['displacement_turn']      = $c->dit_turn     ? (int)$c->dit_turn     : null;
                    $gapMap[$key]['handoff_turn']           = $c->handoff_turn ? (int)$c->handoff_turn : null;
                    $gapMap[$key]['displacement_criteria']  = $c->displacement_criteria  ?? $gapMap[$key]['displacement_criteria'];
                    $gapMap[$key]['displacing_brand']       = $c->t4_winner               ?? $gapMap[$key]['displacing_brand'];
                }

                // Use mechanism_explanation from the classification with highest confidence
                if ($mechanismExplanation !== null) {
                    $existingConf = $gapMap[$key]['confidence'] ?? 0;
                    $thisConf     = (int)($c->confidence_score ?? 0);
                    if ($thisConf >= $existingConf) {
                        $gapMap[$key]['mechanism_explanation'] = $mechanismExplanation;
                        $gapMap[$key]['confidence']            = $thisConf;
                    }
                }
            }
        }

        // For each gap, load evidence submissions and compute readiness
        $status = [];

        foreach ($gapMap as $filter => $gapInfo) {
            $submissions = DB::table('meridian_evidence_submissions')
                ->where('brand_id', $brandId)
                ->where('audit_id', $auditId)
                ->where('filter_type', $filter)
                ->orderByDesc('authority_score')
                ->orderByDesc('created_at')
                ->get();

            $totalCount    = $submissions->count();
            $verifiedCount = $submissions->where('verification_status', 'verified')->count();
            $hasTier1or2   = $submissions
                ->where('verification_status', 'verified')
                ->where('authority_score', '>=', 3)
                ->count() > 0;

            $evidenceItems = $submissions->map(fn($s) => [
                'id'                  => $s->id,
                'source_type'         => $s->source_type,
                'source_url'          => $s->source_url,
                'source_title'        => $s->source_title,
                'doi'                 => $s->doi,
                'free_text'           => $s->free_text,
                'date_published'      => $s->date_published,
                'authority_score'     => (int)$s->authority_score,
                'authority_tier'      => $this->getTierLabel($s->authority_score),
                'verification_status' => $s->verification_status,
                'verification_notes'  => $s->verification_notes,
                'created_at'          => $s->created_at,
            ])->values()->toArray();

            $status[] = array_merge($gapInfo, [
                'total_submitted'  => $totalCount,
                'verified_count'   => $verifiedCount,
                'has_tier1_or_2'   => $hasTier1or2,
                'ready_for_atoms'  => $verifiedCount > 0 && $hasTier1or2,
                'evidence_items'   => $evidenceItems,
            ]);
        }

        // Sort by survival_gap descending (most urgent first)
        usort($status, function($a, $b) {
            $gapA = $a['survival_gap'] ?? -1;
            $gapB = $b['survival_gap'] ?? -1;
            return $gapB <=> $gapA;
        });

        return [
            'gaps_total'   => count($status),
            'gaps_ready'   => count(array_filter($status, fn($s) => $s['ready_for_atoms'])),
            'classified'   => true,
            'filters'      => $status,
        ];
    }

    // -------------------------------------------------------------------------
    // Delete a submission
    // -------------------------------------------------------------------------

    public function delete(string $submissionId, int $agencyId): bool
    {
        $submission = DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->where('agency_id', $agencyId)
            ->first();

        if (!$submission) return false;

        DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->delete();

        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validateSubmission(array $data): void
    {
        if (empty($data['brand_id'])) {
            throw new \InvalidArgumentException('brand_id is required.');
        }
        if (empty($data['filter_type'])) {
            throw new \InvalidArgumentException('filter_type is required.');
        }
        if (!preg_match('/^T[0-8]$/', $data['filter_type'])) {
            throw new \InvalidArgumentException('filter_type must be T0–T8.');
        }
        $hasContent = !empty($data['source_url']) || !empty($data['doi']) || !empty($data['free_text']);
        if (!$hasContent) {
            throw new \InvalidArgumentException('At least one of source_url, doi, or free_text is required.');
        }
    }

    private function computeAuthorityScore(?string $sourceType): int
    {
        return self::AUTHORITY_SCORES[$sourceType] ?? 1;
    }

    private function getTierLabel(?int $score): string
    {
        return match(true) {
            $score >= 4 => 'Tier 1 — Maximum authority',
            $score >= 3 => 'Tier 2 — High authority',
            $score >= 2 => 'Tier 3 — Medium authority',
            default     => 'Tier 4 — Low authority',
        };
    }

    private function checkUrlResolves(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'AIVO-Meridian-Verifier/1.0',
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    private function checkDoiResolves(string $doi): bool
    {
        $doi = ltrim($doi, '/');
        $url = 'https://doi.org/' . $doi;
        return $this->checkUrlResolves($url);
    }
}
