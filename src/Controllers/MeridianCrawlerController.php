<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianCrawlerGenerator;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianCrawlerController
{
    /**
     * GET /api/meridian/crawler?brand_id=X
     */
    public function get(): void
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
            ->first();
        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $generator = new MeridianCrawlerGenerator();
        $result    = $generator->get($brandId, (int)$auth->agency_id);

        if (!$result) {
            json_response(['success' => true, 'data' => null,
                'message' => 'No crawler instructions generated yet. Run generate first.']);
            return;
        }

        json_response(['success' => true, 'data' => $result]);
    }

    /**
     * POST /api/meridian/crawler/generate
     * Body: { "brand_id": 123 }
     */
    public function generate(): void
    {
        $auth    = MeridianAuth::require();
        $body    = request_body();
        $brandId = isset($body['brand_id']) ? (int)$body['brand_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->first();
        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        try {
            $generator = new MeridianCrawlerGenerator();
            $result    = $generator->generate($brandId, (int)$auth->agency_id);
            json_response(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            log_error('[M6] Crawler generate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/crawler/generate-optimised
     * Body: { "brand_id": 123, "audit_id": 456 }
     *
     * Generates an AI-optimised llms.txt using displacement intelligence
     * from M1 classifications, evidence submissions, and approved atoms.
     * Claude writes semantically rich descriptions per entry that directly
     * address the displacement criteria identified for this brand.
     */
    public function generateOptimised(): void
    {
        $auth    = MeridianAuth::require();
        $body    = request_body();
        $brandId = isset($body['brand_id']) ? (int)$body['brand_id'] : null;
        $auditId = isset($body['audit_id']) && $body['audit_id'] ? (int)$body['audit_id'] : null;

        if (!$brandId) {
            http_response_code(400);
            json_response(['error' => 'brand_id is required.']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->first();
        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        try {
            $generator = new MeridianCrawlerGenerator();
            $result    = $generator->generateOptimised($brandId, (int)$auth->agency_id, $auditId);
            json_response(['success' => true, 'data' => $result, 'optimised' => true]);
        } catch (\Throwable $e) {
            log_error('[M6] Crawler generate-optimised error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
