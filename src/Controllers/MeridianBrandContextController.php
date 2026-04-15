<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianBrandContextGenerator;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianBrandContextController
{
    /**
     * POST /api/meridian/brand-context/generate
     * Body: { "brand_id": 1, "audit_id": 26 }
     *
     * Generates all four brand.context variants from M1 classification output.
     */
    public function generate(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId = isset($body['brand_id']) ? (int)$body['brand_id'] : null;
        $auditId = isset($body['audit_id']) ? (int)$body['audit_id'] : null;

        if (!$brandId || !$auditId) {
            http_response_code(400);
            json_response(['error' => 'brand_id and audit_id are required.']);
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

        // Confirm audit belongs to this agency
        $audit = DB::table('meridian_audits')
            ->where('id', $auditId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$audit) {
            http_response_code(403);
            json_response(['error' => 'Audit not found or access denied.']);
            return;
        }

        // Check M1 classifications exist for this audit
        $classificationCount = DB::table('meridian_filter_classifications')
            ->where('audit_id', $auditId)
            ->count();

        if ($classificationCount === 0) {
            http_response_code(422);
            json_response(['error' => 'No filter classifications found for this audit. Run the classifier first (POST /api/meridian/classify/all).']);
            return;
        }

        try {
            $generator = new MeridianBrandContextGenerator();
            $result    = $generator->generate($brandId, $auditId);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[MeridianBrandContext] generate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * GET /api/meridian/brand-context?brand_id=1
     *
     * Returns all generated brand.context variants for a brand.
     */
    public function list(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id query parameter is required.']);
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

        $query = DB::table('meridian_brand_context')
            ->where('brand_id', $brandId)
            ->orderByDesc('created_at');

        if ($auditId) {
            $query->where('audit_id', $auditId);
        }

        $rows = $query->get();

        $out = $rows->map(function ($row) {
            return [
                'id'               => $row->id,
                'audit_id'         => $row->audit_id,
                'variant'          => $row->variant,
                'schema_version'   => $row->schema_version,
                'gap_count'        => $row->gap_count,
                'deployment_status'=> $row->deployment_status,
                'deployment_url'   => $row->deployment_url,
                'deployed_at'      => $row->deployed_at,
                'last_verified_at' => $row->last_verified_at,
                'created_at'       => $row->created_at,
            ];
        })->toArray();

        json_response(['success' => true, 'data' => $out]);
    }

    /**
     * GET /api/meridian/brand-context/download?id={uuid}
     *
     * Returns the full JSON-LD file content for download.
     */
    public function download(): void
    {
        $auth = MeridianAuth::require();
        $id   = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            json_response(['error' => 'id query parameter is required.']);
            return;
        }

        $row = DB::table('meridian_brand_context')
            ->where('id', $id)
            ->first();

        if (!$row) {
            http_response_code(404);
            json_response(['error' => 'File not found.']);
            return;
        }

        // Verify brand belongs to this agency
        $brand = DB::table('meridian_brands')
            ->where('id', $row->brand_id)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Access denied.']);
            return;
        }

        $filename = 'brand.context' . ($row->variant !== 'universal' ? '.' . $row->variant : '');

        header('Content-Type: application/ld+json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $row->file_content;
        exit;
    }
}
