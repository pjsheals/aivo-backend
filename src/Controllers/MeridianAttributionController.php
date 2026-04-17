<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianAttributionService;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianAttributionController
{
    /**
     * POST /api/meridian/attribution/link
     * Body: { "atom_id": "uuid", "destination": "https://...", "source": "optional", "medium": "optional", "campaign": "optional" }
     */
    public function createLink(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $atomId      = $body['atom_id']     ?? null;
        $destination = $body['destination'] ?? null;

        if (!$atomId || !$destination) {
            http_response_code(400);
            json_response(['error' => 'atom_id and destination are required.']);
            return;
        }

        if (!filter_var($destination, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            json_response(['error' => 'destination must be a valid URL.']);
            return;
        }

        $atom = DB::table('meridian_atoms')
            ->where('id', $atomId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$atom) {
            http_response_code(403);
            json_response(['error' => 'Atom not found or access denied.']);
            return;
        }

        try {
            $service = new MeridianAttributionService();
            $result  = $service->createLink($atomId, $destination, (int)$auth->agency_id, [
                'source'   => $body['source']   ?? 'meridian',
                'medium'   => $body['medium']   ?? 'atom',
                'campaign' => $body['campaign'] ?? null,
                'label'    => $body['label']    ?? null,
            ]);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[M7] createLink error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/attribution/link/delete
     * Body: { "link_id": "uuid" }
     */
    public function deleteLink(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $linkId = $body['link_id'] ?? null;

        if (!$linkId) {
            http_response_code(400);
            json_response(['error' => 'link_id is required.']);
            return;
        }

        try {
            $service = new MeridianAttributionService();
            $deleted = $service->deleteLink($linkId, (int)$auth->agency_id);

            if (!$deleted) {
                http_response_code(404);
                json_response(['error' => 'Link not found or access denied.']);
                return;
            }

            json_response(['success' => true, 'data' => ['link_id' => $linkId]]);

        } catch (\Throwable $e) {
            log_error('[M7] deleteLink error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * GET /api/meridian/attribution/stats?brand_id=X&atom_id=Y(optional)
     */
    public function stats(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $atomId  = $_GET['atom_id'] ?? null;

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

        $service = new MeridianAttributionService();
        $stats   = $service->getStats($brandId, (int)$auth->agency_id, $atomId);

        json_response(['success' => true, 'data' => $stats]);
    }

    /**
     * GET /r/{token}
     *
     * Public redirect endpoint. No auth required.
     */
    public function redirect(): void
    {
        $uri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $token = basename($uri);

        if (!$token) {
            http_response_code(400);
            echo 'Invalid redirect link.';
            return;
        }

        $service     = new MeridianAttributionService();
        $destination = $service->processClick($token, [
            'ip'         => $_SERVER['REMOTE_ADDR']    ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referrer'   => $_SERVER['HTTP_REFERER']    ?? '',
        ]);

        if (!$destination) {
            http_response_code(404);
            echo 'Link not found.';
            return;
        }

        header('Location: ' . $destination, true, 302);
        exit;
    }
}
