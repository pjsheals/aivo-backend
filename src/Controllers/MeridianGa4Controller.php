<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianGa4Service;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianGa4Controller
 *
 * GET  /api/meridian/ga4/config?brand_id=X   — Get GA4 config for a brand
 * POST /api/meridian/ga4/config/save          — Save GA4 config (upsert)
 * POST /api/meridian/ga4/config/test          — Test connection via debug endpoint
 */
class MeridianGa4Controller
{
    /**
     * GET /api/meridian/ga4/config?brand_id=X
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
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Brand not found or access denied.']);
            return;
        }

        $service = new MeridianGa4Service();
        $config  = $service->getConfig($brandId, (int)$auth->agency_id);

        json_response(['success' => true, 'data' => $config]);
    }

    /**
     * POST /api/meridian/ga4/config/save
     * Body: { brand_id, measurement_id, api_secret }
     */
    public function save(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId       = isset($body['brand_id'])       ? (int)$body['brand_id']           : null;
        $measurementId = isset($body['measurement_id']) ? trim($body['measurement_id'])     : null;
        $apiSecret     = isset($body['api_secret'])     ? trim($body['api_secret'])         : null;

        if (!$brandId || !$measurementId || !$apiSecret) {
            http_response_code(400);
            json_response(['error' => 'brand_id, measurement_id, and api_secret are required.']);
            return;
        }

        // Validate measurement_id format
        if (!preg_match('/^G-[A-Z0-9]+$/i', $measurementId)) {
            http_response_code(400);
            json_response(['error' => 'measurement_id must be in format G-XXXXXXXXXX.']);
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

        try {
            $service = new MeridianGa4Service();
            $result  = $service->saveConfig($brandId, (int)$auth->agency_id, $measurementId, $apiSecret);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[GA4] save config error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/ga4/config/test
     * Body: { brand_id }
     *
     * Fires a test event to GA4's debug/mp/collect endpoint.
     * Returns validation messages — empty array means config is valid.
     */
    public function test(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId = isset($body['brand_id']) ? (int)$body['brand_id'] : null;

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

        try {
            $service = new MeridianGa4Service();
            $result  = $service->testConnection($brandId, (int)$auth->agency_id);

            json_response(['success' => $result['success'], 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[GA4] test connection error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }
}
