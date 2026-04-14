<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianAgencyController
 *
 * POST /api/meridian/agency/logo   — Upload/update agency logo (base64)
 */
class MeridianAgencyController
{
    /**
     * POST /api/meridian/agency/logo
     *
     * Body: { logo_data: "data:image/png;base64,..." }
     * Accepts PNG, JPEG, GIF, WebP. Max ~500KB (base64 ~680KB string).
     */
    public function updateLogo(): void
    {
        $user = MeridianAuth::requireAuth();
        if (!$user) return;

        $body      = request_body();
        $logoData  = trim($body['logo_data'] ?? '');

        // Allow clearing the logo
        if ($logoData === '') {
            DB::table('meridian_agencies')
                ->where('id', $user->agency_id)
                ->update(['logo_url' => null, 'updated_at' => now()]);
            json_response(['status' => 'ok', 'logo_url' => null]);
            return;
        }

        // Validate it's a base64 data URL of an image
        if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/i', $logoData)) {
            http_response_code(422);
            json_response(['error' => 'Invalid image format. Please upload a PNG, JPEG, GIF, or WebP file.']);
            return;
        }

        // Check size — base64 string length ~= 1.37× raw bytes; 680KB string ≈ 500KB image
        if (strlen($logoData) > 700000) {
            http_response_code(422);
            json_response(['error' => 'Image too large. Please upload an image under 500KB.']);
            return;
        }

        // Only admins can update agency branding
        if (!in_array($user->role, ['admin', 'owner'], true)) {
            http_response_code(403);
            json_response(['error' => 'Only agency admins can update branding.']);
            return;
        }

        DB::table('meridian_agencies')
            ->where('id', $user->agency_id)
            ->update(['logo_url' => $logoData, 'updated_at' => now()]);

        json_response(['status' => 'ok', 'logo_url' => $logoData]);
    }
}
