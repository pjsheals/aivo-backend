<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianAttributionService — Module 7
 *
 * First-party attribution for published atoms.
 * Generates tracked redirect URLs, logs clicks, returns stats.
 *
 * Link format: /r/{token}
 *
 * All clicks stored in meridian_attribution_clicks.
 * All links stored in meridian_attribution_links.
 *
 * Stage 7 update: processClick() now fires a meridian_click event to the
 * brand's configured GA4 property via MeridianGa4Service.
 */
class MeridianAttributionService
{
    // -------------------------------------------------------------------------
    // Generate a tracked link for a published atom
    // -------------------------------------------------------------------------

    public function createLink(string $atomId, string $destination, int $agencyId, array $options = []): array
    {
        $atom = DB::table('meridian_atoms')->where('id', $atomId)->first();
        if (!$atom) throw new \RuntimeException("Atom {$atomId} not found.");

        $brand = DB::table('meridian_brands')->find($atom->brand_id);
        if (!$brand) throw new \RuntimeException("Brand not found.");

        $token = $this->generateToken();

        // Build destination URL with km_ attribution params
        $separator  = str_contains($destination, '?') ? '&' : '?';
        $trackedUrl = $destination
            . $separator
            . http_build_query([
                'km_source' => $options['source'] ?? 'meridian',
                'km_atom'   => $atomId,
                'km_filter' => $atom->filter_type,
                'km_brand'  => $this->brandSlug($brand->name),
                'km_var'    => $atom->model_variant,
            ]);

        $id = $this->uuid();

        DB::table('meridian_attribution_links')->insert([
            'id'          => $id,
            'token'       => $token,
            'atom_id'     => $atomId,
            'brand_id'    => $atom->brand_id,
            'agency_id'   => $agencyId,
            'destination' => $destination,
            'tracked_url' => $trackedUrl,
            'source'      => $options['source']   ?? 'meridian',
            'medium'      => $options['medium']   ?? 'atom',
            'campaign'    => $options['campaign'] ?? $atom->filter_type,
            'label'       => $options['label']    ?? $atom->atom_identifier,
            'click_count' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $redirectUrl = env('APP_URL', 'https://aivo-backend-production-2184.up.railway.app') . '/r/' . $token;

        return [
            'id'           => $id,
            'token'        => $token,
            'redirect_url' => $redirectUrl,
            'destination'  => $destination,
            'tracked_url'  => $trackedUrl,
            'atom_id'      => $atomId,
        ];
    }

    // -------------------------------------------------------------------------
    // Process a redirect click — log + fire GA4 + redirect
    // -------------------------------------------------------------------------

    public function processClick(string $token, array $requestData = []): ?string
    {
        $link = DB::table('meridian_attribution_links')
            ->where('token', $token)
            ->first();

        if (!$link) return null;

        // Log the click to DB
        DB::table('meridian_attribution_clicks')->insert([
            'id'         => $this->uuid(),
            'link_id'    => $link->id,
            'atom_id'    => $link->atom_id,
            'brand_id'   => $link->brand_id,
            'agency_id'  => $link->agency_id,
            'ip_hash'    => hash('sha256', $requestData['ip'] ?? ''),
            'user_agent' => substr($requestData['user_agent'] ?? '', 0, 500),
            'referrer'   => substr($requestData['referrer']   ?? '', 0, 500),
            'country'    => $requestData['country']           ?? null,
            'clicked_at' => now(),
            'created_at' => now(),
        ]);

        // Increment click counter
        DB::table('meridian_attribution_links')
            ->where('id', $link->id)
            ->increment('click_count');

        // Fire GA4 meridian_click event if brand has GA4 configured
        try {
            $atom = DB::table('meridian_atoms')->where('id', $link->atom_id)->first();

            (new MeridianGa4Service())->fireClickEvent(
                (int)$link->brand_id,
                (int)$link->agency_id,
                [
                    'atom_filter'      => $link->campaign ?? ($atom->filter_type ?? ''),
                    'platform_variant' => $atom->model_variant ?? 'universal',
                    'campaign'         => $link->campaign  ?? '',
                    'brand_slug'       => $this->brandSlug($link->brand_id ? (DB::table('meridian_brands')->find($link->brand_id)?->name ?? '') : ''),
                    'link_token'       => $token,
                    'source'           => $link->source ?? 'meridian',
                    'medium'           => $link->medium  ?? 'atom',
                ]
            );
        } catch (\Throwable $e) {
            // GA4 failure never blocks the redirect
            log_error('[M7] GA4 event failed', ['error' => $e->getMessage(), 'token' => $token]);
        }

        return $link->tracked_url;
    }

    // -------------------------------------------------------------------------
    // Delete a tracked link
    // -------------------------------------------------------------------------

    public function deleteLink(string $linkId, int $agencyId): bool
    {
        $link = DB::table('meridian_attribution_links')
            ->where('id', $linkId)
            ->where('agency_id', $agencyId)
            ->first();

        if (!$link) return false;

        DB::table('meridian_attribution_links')
            ->where('id', $linkId)
            ->delete();

        return true;
    }

    // -------------------------------------------------------------------------
    // Stats for a brand or atom
    // -------------------------------------------------------------------------

    public function getStats(int $brandId, int $agencyId, ?string $atomId = null): array
    {
        $linkQuery = DB::table('meridian_attribution_links')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId);

        if ($atomId) $linkQuery->where('atom_id', $atomId);

        $links = $linkQuery->orderBy('created_at', 'desc')->get();

        $totalClicks = 0;
        $linkStats   = [];

        foreach ($links as $link) {
            $dailyClicks = DB::table('meridian_attribution_clicks')
                ->where('link_id', $link->id)
                ->where('clicked_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->selectRaw("DATE(clicked_at) as date, COUNT(*) as clicks")
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $totalClicks += $link->click_count;

            $linkStats[] = [
                'id'           => $link->id,
                'token'        => $link->token,
                'redirect_url' => env('APP_URL', 'https://aivo-backend-production-2184.up.railway.app') . '/r/' . $link->token,
                'destination'  => $link->destination,
                'atom_id'      => $link->atom_id,
                'source'       => $link->source,
                'campaign'     => $link->campaign,
                'click_count'  => (int)$link->click_count,
                'daily_clicks' => $dailyClicks->map(fn($d) => [
                    'date'   => $d->date,
                    'clicks' => (int)$d->clicks,
                ])->toArray(),
                'created_at'   => $link->created_at,
            ];
        }

        return [
            'brand_id'     => $brandId,
            'total_links'  => count($links),
            'total_clicks' => $totalClicks,
            'links'        => $linkStats,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function brandSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
    }
}
