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
     *
     * Returns the most recently generated crawler instructions for a brand.
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

        // Confirm brand belongs to this agency
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
            json_response(['success' => true, 'data' => null, 'message' => 'No crawler instructions generated yet. Run generate first.']);
            return;
        }

        json_response(['success' => true, 'data' => $result]);
    }

    /**
     * POST /api/meridian/crawler/generate
     * Body: { "brand_id": 123 }
     *
     * Generates robots.txt and llms.txt for a brand from its published atoms.
     */
    public function generate(): void
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
}
