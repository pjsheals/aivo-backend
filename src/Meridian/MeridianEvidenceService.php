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
        'peer_reviewed'  => 4,
        'regulatory'     => 4,
        'clinical'       => 4,
        'expert'         => 3,
        'third_party'    => 3,
        'press'          => 2,
        'corporate'      => 1,
        'self_published' => 1,
    ];

    // Known Tier 1 domains for source type validation
    private const TIER1_DOMAINS = [
        'pubmed.ncbi.nlm.nih.gov', 'ncbi.nlm.nih.gov',
        'doi.org', 'zenodo.org',
        'fda.gov', 'ema.europa.eu', 'mhra.gov.uk',
        'clinicaltrials.gov',
        'journals.plos.org', 'nature.com', 'sciencedirect.com',
        'jamanetwork.com', 'bmj.com', 'thelancet.com',
        'dermatologyresearch.net', 'jaad.org',
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

        return [
            'submission_id'    => $id,
            'authority_score'  => $authorityScore,
            'authority_tier'   => $this->getTierLabel($authorityScore),
            'verification_status' => 'pending',
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

        // Check URL resolves
        if ($submission->source_url) {
            $urlResolves = $this->checkUrlResolves($submission->source_url);
            if (!$urlResolves) {
                $notes[] = 'URL did not return HTTP 200.';
            }
        }

        // Check DOI resolves
        if ($submission->doi) {
            $doiResolves = $this->checkDoiResolves($submission->doi);
            if (!$doiResolves) {
                $notes[] = 'DOI did not resolve via doi.org.';
            }
        }

        // Determine final verification status
        $hasContent   = $submission->source_url || $submission->doi || $submission->free_text;
        $urlOk        = !$submission->source_url || $urlResolves;
        $doiOk        = !$submission->doi        || $doiResolves;
        $status       = ($hasContent && $urlOk && $doiOk) ? 'verified' : 'failed';

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
            'submission_id'      => $submissionId,
            'verified'           => $status === 'verified',
            'verification_status'=> $status,
            'url_resolves'       => $urlResolves,
            'doi_resolves'       => $doiResolves,
            'notes'              => $notes,
            'authority_score'    => $submission->authority_score,
        ];
    }

    // -------------------------------------------------------------------------
    // Get submissions for a brand/audit grouped by filter
    // -------------------------------------------------------------------------

    public function getByBrand(int $brandId, ?int $auditId = null): array
    {
        $query = DB::table('meridian_evidence_submissions')
            ->where('brand_id', $brandId)
            ->orderByDesc('created_at');

        if ($auditId) {
            $query->where('audit_id', $auditId);
        }

        $rows = $query->get();

        // Group by filter_type
        $grouped = [];
        foreach ($rows as $row) {
            $filter = $row->filter_type;
            if (!isset($grouped[$filter])) {
                $grouped[$filter] = [];
            }
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
    // Tells M4 which gaps have enough verified evidence to generate atoms
    // -------------------------------------------------------------------------

    public function getGapCompletionStatus(int $brandId, int $auditId): array
    {
        // Load gaps from M1 classifications
        $classifications = DB::table('meridian_filter_classifications')
            ->where('audit_id', $auditId)
            ->get();

        $gaps = [];
        foreach ($classifications as $c) {
            $gapData = json_decode($c->evidence_gaps ?? '[]', true);
            foreach ($gapData as $gap) {
                $filter = $gap['filter'] ?? null;
                if ($filter && !isset($gaps[$filter])) {
                    $gaps[$filter] = [
                        'filter'   => $filter,
                        'platform' => $c->platform,
                        'gap'      => $gap['gap'] ?? '',
                    ];
                }
            }
        }

        // For each gap, check if verified evidence exists
        $status = [];
        foreach ($gaps as $filter => $gapInfo) {
            $verifiedCount = DB::table('meridian_evidence_submissions')
                ->where('brand_id', $brandId)
                ->where('audit_id', $auditId)
                ->where('filter_type', $filter)
                ->where('verification_status', 'verified')
                ->count();

            $totalCount = DB::table('meridian_evidence_submissions')
                ->where('brand_id', $brandId)
                ->where('audit_id', $auditId)
                ->where('filter_type', $filter)
                ->count();

            // Check if at least one Tier 1 or Tier 2 source exists for T3/T4 gaps
            $hasTier1or2 = DB::table('meridian_evidence_submissions')
                ->where('brand_id', $brandId)
                ->where('audit_id', $auditId)
                ->where('filter_type', $filter)
                ->where('verification_status', 'verified')
                ->where('authority_score', '>=', 3)
                ->count() > 0;

            $status[$filter] = [
                'filter'          => $filter,
                'gap_description' => $gapInfo['gap'],
                'platform'        => $gapInfo['platform'],
                'total_submitted' => $totalCount,
                'verified_count'  => $verifiedCount,
                'has_tier1_or_2'  => $hasTier1or2,
                'ready_for_atoms' => $verifiedCount > 0 && $hasTier1or2,
            ];
        }

        return [
            'gaps_total'        => count($gaps),
            'gaps_ready'        => count(array_filter($status, fn($s) => $s['ready_for_atoms'])),
            'filters'           => array_values($status),
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
        $doi     = ltrim($doi, '/');
        $url     = 'https://doi.org/' . $doi;
        return $this->checkUrlResolves($url);
    }
}
