<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianBrandPackageGenerator;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianPackageController
{
    /**
     * POST /api/meridian/package/generate
     * Body: { brand_id, audit_id }
     *
     * Assembles all five platform Brand Intelligence Package JSON files
     * from approved atoms, verified evidence, M1 displacement map, and M2 brand context.
     * Stores in meridian_brand_packages (upsert per platform).
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

        $audit = DB::table('meridian_audits')
            ->where('id', $auditId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$audit) {
            http_response_code(403);
            json_response(['error' => 'Audit not found or access denied.']);
            return;
        }

        // Require at least one approved atom before generating packages
        $approvedCount = DB::table('meridian_atoms')
            ->where('brand_id', $brandId)
            ->where('approval_status', 'approved')
            ->count();

        if ($approvedCount === 0) {
            http_response_code(422);
            json_response(['error' => 'No approved atoms found. Approve at least one atom before generating packages.']);
            return;
        }

        try {
            $generator = new MeridianBrandPackageGenerator();
            $result    = $generator->generate($brandId, $auditId, (int)$auth->agency_id);

            json_response(['success' => true, 'data' => $result]);

        } catch (\RuntimeException $e) {
            http_response_code(422);
            json_response(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_error('[M9] Package generate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * GET /api/meridian/package/status?brand_id=X
     *
     * Returns current package state for all five platforms.
     * Ungenerated platforms are included with null timestamps and zero counts.
     */
    public function status(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;

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

        $generator = new MeridianBrandPackageGenerator();
        $result    = $generator->status($brandId, (int)$auth->agency_id);

        json_response(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/meridian/package/download?brand_id=X&platform=gemini
     *
     * Returns the generated JSON file as a download attachment.
     * The file is ready to deploy to /.well-known/llm/ on the client's domain.
     */
    public function download(): void
    {
        $auth     = MeridianAuth::require();
        $brandId  = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $platform = isset($_GET['platform'])  ? strtolower(trim($_GET['platform'])) : null;

        if (!$brandId || !$platform) {
            http_response_code(400);
            json_response(['error' => 'brand_id and platform are required.']);
            return;
        }

        if (!in_array($platform, MeridianBrandPackageGenerator::PLATFORMS, true)) {
            http_response_code(400);
            json_response(['error' => 'platform must be one of: ' . implode(', ', MeridianBrandPackageGenerator::PLATFORMS)]);
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

        $generator = new MeridianBrandPackageGenerator();
        $content   = $generator->download($brandId, (int)$auth->agency_id, $platform);

        if (!$content) {
            http_response_code(404);
            json_response(['error' => "No package found for platform '{$platform}'. Run generate first."]);
            return;
        }

        $brandSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($brand->name)));
        $filename  = "{$brandSlug}-{$platform}.json";

        header('Content-Type: application/json; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache, must-revalidate');

        echo json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
