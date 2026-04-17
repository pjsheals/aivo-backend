<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianClientController
 *
 * GET  /api/meridian/clients          — List all clients for agency
 * POST /api/meridian/clients/create   — Create new client
 * POST /api/meridian/clients/update   — Update client (id in body)
 * POST /api/meridian/clients/delete   — Delete client (id in body)
 *
 * Metering note: clients are unlimited at all plan tiers (Boutique/Studio/Network/Enterprise).
 * The only enforced limit is max_brands. Client count is organisational convenience only.
 */
class MeridianClientController
{
    // ── GET /api/meridian/clients ────────────────────────────────
    public function list(): void
    {
        $auth = MeridianAuth::require('viewer');

        try {
            $clients = DB::table('meridian_clients')
                ->where('agency_id', $auth->agency_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();

            $result = $clients->map(function ($client) {
                $brands = DB::table('meridian_brands')
                    ->where('client_id', $client->id)
                    ->where('brand_type', 'monitored')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->get(['id', 'name', 'category', 'current_rcs', 'current_rar',
                           'current_ad_verdict', 'last_audited_at']);

                $totalRar = $brands->sum('current_rar');
                $avgRcs   = $brands->avg('current_rcs');

                return [
                    'id'            => (int)$client->id,
                    'name'          => $client->name,
                    'industry'      => $client->industry,
                    'website'       => $client->website,
                    'contactName'   => $client->contact_name,
                    'contactEmail'  => $client->contact_email,
                    'brandCount'    => $brands->count(),
                    'totalRar'      => $totalRar ? round((float)$totalRar, 2) : null,
                    'avgRcs'        => $avgRcs   ? (int)round($avgRcs)        : null,
                    'createdAt'     => $client->created_at,
                ];
            });

            json_response(['status' => 'ok', 'clients' => $result]);

        } catch (\Throwable $e) {
            log_error('[Meridian] clients.list error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/clients/create ────────────────────────
    public function create(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();

        $name = trim($body['name'] ?? '');
        if (!$name) {
            http_response_code(422);
            json_response(['error' => 'Client name is required.']);
            return;
        }

        // No client limit — clients are unlimited at all Meridian plan tiers.
        // Brand count (max_brands) is the only enforced limit.

        try {
            $id = DB::table('meridian_clients')->insertGetId([
                'agency_id'     => $auth->agency_id,
                'name'          => $name,
                'industry'      => trim($body['industry']      ?? ''),
                'website'       => trim($body['website']       ?? ''),
                'contact_name'  => trim($body['contact_name']  ?? ''),
                'contact_email' => trim($body['contact_email'] ?? ''),
                'notes'         => trim($body['notes']         ?? ''),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table('meridian_audit_log')->insert([
                'agency_id'   => $auth->agency_id,
                'user_id'     => $auth->user_id,
                'action'      => 'client.created',
                'entity_type' => 'client',
                'entity_id'   => $id,
                'metadata'    => json_encode(['name' => $name]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);

            $client = DB::table('meridian_clients')->find($id);
            json_response(['status' => 'ok', 'client' => $client]);

        } catch (\Throwable $e) {
            log_error('[Meridian] clients.create error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/clients/update ────────────────────────
    public function update(): void
    {
        $auth = MeridianAuth::require('analyst');
        $body = request_body();
        $id   = (int)($body['id'] ?? 0);

        if (!$id) {
            http_response_code(422);
            json_response(['error' => 'Client ID is required.']);
            return;
        }

        $client = DB::table('meridian_clients')
            ->where('id', $id)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$client) {
            http_response_code(404);
            json_response(['error' => 'Client not found.']);
            return;
        }

        try {
            $updates = ['updated_at' => now()];

            if (isset($body['name']))          $updates['name']          = trim($body['name']);
            if (isset($body['industry']))      $updates['industry']      = trim($body['industry']);
            if (isset($body['website']))       $updates['website']       = trim($body['website']);
            if (isset($body['contact_name']))  $updates['contact_name']  = trim($body['contact_name']);
            if (isset($body['contact_email'])) $updates['contact_email'] = trim($body['contact_email']);
            if (isset($body['notes']))         $updates['notes']         = trim($body['notes']);

            DB::table('meridian_clients')->where('id', $id)->update($updates);

            json_response(['status' => 'ok', 'client' => DB::table('meridian_clients')->find($id)]);

        } catch (\Throwable $e) {
            log_error('[Meridian] clients.update error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/clients/delete ────────────────────────
    public function delete(): void
    {
        $auth = MeridianAuth::require('admin');
        $body = request_body();
        $id   = (int)($body['id'] ?? 0);

        if (!$id) {
            http_response_code(422);
            json_response(['error' => 'Client ID is required.']);
            return;
        }

        $client = DB::table('meridian_clients')
            ->where('id', $id)
            ->where('agency_id', $auth->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$client) {
            http_response_code(404);
            json_response(['error' => 'Client not found.']);
            return;
        }

        try {
            DB::table('meridian_clients')
                ->where('id', $id)
                ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);

            DB::table('meridian_audit_log')->insert([
                'agency_id'   => $auth->agency_id,
                'user_id'     => $auth->user_id,
                'action'      => 'client.deleted',
                'entity_type' => 'client',
                'entity_id'   => $id,
                'metadata'    => json_encode(['name' => $client->name]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[Meridian] clients.delete error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }
}
