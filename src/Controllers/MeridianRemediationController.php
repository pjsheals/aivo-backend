<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianRemediationEngine;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianRemediationController
{
    /**
     * POST /api/meridian/remediation/generate
     * Body: { brand_id, audit_id?, force? }
     */
    public function generate(): void
    {
        $auth    = MeridianAuth::require('viewer');
        $body    = request_body();
        $brandId = (int)($body['brand_id'] ?? 0);
        $auditId = isset($body['audit_id']) && $body['audit_id'] ? (int)$body['audit_id'] : null;
        $force   = (bool)($body['force'] ?? false);

        if (!$brandId) {
            http_response_code(422);
            json_response(['error' => 'brand_id required']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found']);
            return;
        }

        // If audit_id provided, verify it belongs to this brand + agency
        if ($auditId) {
            $audit = DB::table('meridian_audits')
                ->where('id', $auditId)
                ->where('brand_id', $brandId)
                ->where('agency_id', $auth->agency_id)
                ->first();
            if (!$audit) {
                http_response_code(404);
                json_response(['error' => 'Audit not found']);
                return;
            }
        }

        try {
            $engine = new MeridianRemediationEngine();
            $result = $engine->generateRemediation($brandId, $auth->agency_id, $force, $auditId);

            if ($result['status'] === 'error') {
                http_response_code(500);
                json_response(['error' => $result['message']]);
                return;
            }

            json_response(['status' => $result['status'], 'data' => $result['data']]);

        } catch (\Throwable $e) {
            log_error('[Meridian] remediation.generate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error']);
        }
    }

    /**
     * GET /api/meridian/remediation?brand_id=X
     */
    public function fetch(): void
    {
        $auth    = MeridianAuth::require('viewer');
        $brandId = (int)($_GET['brand_id'] ?? 0);

        if (!$brandId) {
            http_response_code(422);
            json_response(['error' => 'brand_id required']);
            return;
        }

        $brand = DB::table('meridian_brands')
            ->where('id', $brandId)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$brand) {
            http_response_code(404);
            json_response(['error' => 'Brand not found']);
            return;
        }

        $row = DB::table('meridian_brand_audit_results')
            ->where('brand_id', $brandId)
            ->whereNotNull('remediation_json')
            ->orderByDesc('created_at')
            ->first(['remediation_json', 'remediation_generated_at']);

        if (!$row) {
            http_response_code(404);
            json_response(['status' => 'none']);
            return;
        }

        $data = json_decode($row->remediation_json, true);
        $data['_generated_at'] = $row->remediation_generated_at;

        json_response(['status' => 'ok', 'data' => $data]);
    }
}
