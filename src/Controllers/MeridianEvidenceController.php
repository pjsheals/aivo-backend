<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianEvidenceService;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianEvidenceController
{
    /**
     * POST /api/meridian/evidence/submit
     *
     * Body: {
     *   brand_id, audit_id, filter_type, field_name,
     *   source_type, source_url, source_title, doi,
     *   free_text, date_published
     * }
     */
    public function submit(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId = isset($body['brand_id']) ? (int)$body['brand_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        // Confirm brand belongs to this agency
        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        try {
            $service = new MeridianEvidenceService();
            $result  = $service->submit($body, $auth->agency_id, $auth->user_id);

            json_response(['success' => true, 'data' => $result]);

        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            json_response(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_error('[MeridianEvidence] submit error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * GET /api/meridian/evidence?brand_id=1&audit_id=26
     *
     * Returns all submissions grouped by filter_type.
     */
    public function list(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $service = new MeridianEvidenceService();
        $grouped = $service->getByBrand($brandId, $auditId);

        json_response(['success' => true, 'data' => $grouped]);
    }

    /**
     * GET /api/meridian/evidence/gaps?brand_id=1&audit_id=26
     *
     * Returns gap completion status — which gaps are ready for M4 atom generation.
     */
    public function gaps(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : null;

        if (!$brandId || !$auditId) {
            http_response_code(400);
            json_response(['error' => 'brand_id and audit_id are required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $service = new MeridianEvidenceService();
        $status  = $service->getGapCompletionStatus($brandId, $auditId);

        json_response(['success' => true, 'data' => $status]);
    }

    /**
     * POST /api/meridian/evidence/verify
     *
     * Body: { "submission_id": "uuid" }
     *
     * Triggers URL check + DOI lookup on a single submission.
     */
    public function verify(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $submissionId = $body['submission_id'] ?? null;

        if (!$submissionId) {
            http_response_code(400);
            json_response(['error' => 'submission_id is required.']);
            return;
        }

        // Confirm submission belongs to this agency
        $submission = DB::table('meridian_evidence_submissions')
            ->where('id', $submissionId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$submission) {
            http_response_code(403);
            json_response(['error' => 'Submission not found or access denied.']);
            return;
        }

        try {
            $service = new MeridianEvidenceService();
            $result  = $service->verify($submissionId);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[MeridianEvidence] verify error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/evidence/delete
     *
     * Body: { "submission_id": "uuid" }
     */
    public function delete(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $submissionId = $body['submission_id'] ?? null;

        if (!$submissionId) {
            http_response_code(400);
            json_response(['error' => 'submission_id is required.']);
            return;
        }

        $service = new MeridianEvidenceService();
        $deleted = $service->delete($submissionId, $auth->agency_id);

        if (!$deleted) {
            http_response_code(404);
            json_response(['success' => false, 'error' => 'Submission not found or access denied.']);
            return;
        }

        json_response(['success' => true]);
    }
}
