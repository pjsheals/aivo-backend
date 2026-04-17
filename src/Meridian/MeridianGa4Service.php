<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianGa4Service
 *
 * Manages per-brand GA4 Measurement Protocol configuration and event firing.
 *
 * Each brand can have one GA4 config (Measurement ID + API Secret).
 * When a tracked link is clicked, a meridian_click event is fired to the
 * client's GA4 property via the Measurement Protocol.
 *
 * Event name: meridian_click
 * Parameters: atom_filter, platform_variant, campaign, brand_slug, link_token
 *
 * GA4 Measurement Protocol endpoint:
 *   POST https://www.google-analytics.com/mp/collect?measurement_id={mid}&api_secret={secret}
 *
 * Validation endpoint (test connection):
 *   POST https://www.google-analytics.com/debug/mp/collect?measurement_id={mid}&api_secret={secret}
 */
class MeridianGa4Service
{
    private const MP_ENDPOINT       = 'https://www.google-analytics.com/mp/collect';
    private const MP_DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';
    private const CURL_TIMEOUT      = 3; // seconds — keeps redirect latency acceptable

    // -------------------------------------------------------------------------
    // Save GA4 config for a brand (upsert)
    // -------------------------------------------------------------------------

    public function saveConfig(int $brandId, int $agencyId, string $measurementId, string $apiSecret): array
    {
        $existing = DB::table('meridian_ga4_configs')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->first();

        $data = [
            'brand_id'       => $brandId,
            'agency_id'      => $agencyId,
            'measurement_id' => $measurementId,
            'api_secret'     => $apiSecret,
            'is_active'      => true,
            'updated_at'     => now(),
        ];

        if ($existing) {
            DB::table('meridian_ga4_configs')
                ->where('id', $existing->id)
                ->update($data);
            $id = $existing->id;
        } else {
            $data['created_at'] = now();
            $id = DB::table('meridian_ga4_configs')->insertGetId($data);
        }

        return [
            'id'             => $id,
            'brand_id'       => $brandId,
            'measurement_id' => $measurementId,
            'is_active'      => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Get GA4 config for a brand
    // -------------------------------------------------------------------------

    public function getConfig(int $brandId, int $agencyId): ?array
    {
        $row = DB::table('meridian_ga4_configs')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->first();

        if (!$row) return null;

        return [
            'id'              => $row->id,
            'brand_id'        => $row->brand_id,
            'measurement_id'  => $row->measurement_id,
            // Never return api_secret to the frontend — return masked version only
            'api_secret_hint' => $this->maskSecret($row->api_secret),
            'is_active'       => (bool)$row->is_active,
            'last_tested_at'  => $row->last_tested_at ?? null,
            'last_test_status'=> $row->last_test_status ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Test connection — fires a validation event via the GA4 debug endpoint
    // -------------------------------------------------------------------------

    public function testConnection(int $brandId, int $agencyId): array
    {
        $row = DB::table('meridian_ga4_configs')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->first();

        if (!$row) {
            return ['success' => false, 'error' => 'No GA4 config found for this brand. Save config first.'];
        }

        $payload = $this->buildPayload($brandId, 'meridian_test', [
            'test'    => true,
            'brand_id'=> $brandId,
        ]);

        $url = self::MP_DEBUG_ENDPOINT
            . '?measurement_id=' . urlencode($row->measurement_id)
            . '&api_secret='     . urlencode($row->api_secret);

        $response = $this->postToGa4($url, $payload);

        $status = 'failed';
        $messages = [];

        if ($response !== null) {
            $decoded = json_decode($response, true);
            $messages = $decoded['validationMessages'] ?? [];
            $status = empty($messages) ? 'ok' : 'validation_errors';
        }

        DB::table('meridian_ga4_configs')
            ->where('id', $row->id)
            ->update([
                'last_tested_at'   => now(),
                'last_test_status' => $status,
                'updated_at'       => now(),
            ]);

        return [
            'success'             => $status === 'ok',
            'status'              => $status,
            'measurement_id'      => $row->measurement_id,
            'validation_messages' => $messages,
            'message'             => $status === 'ok'
                ? 'Connection verified. Test event accepted by GA4.'
                : 'GA4 returned validation errors — check measurement_id and api_secret.',
        ];
    }

    // -------------------------------------------------------------------------
    // Fire a meridian_click event — called from MeridianAttributionService
    // -------------------------------------------------------------------------

    public function fireClickEvent(int $brandId, int $agencyId, array $params): void
    {
        $row = DB::table('meridian_ga4_configs')
            ->where('brand_id', $brandId)
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->first();

        if (!$row) return; // No config — silent no-op

        $payload = $this->buildPayload($brandId, 'meridian_click', $params);

        $url = self::MP_ENDPOINT
            . '?measurement_id=' . urlencode($row->measurement_id)
            . '&api_secret='     . urlencode($row->api_secret);

        // Fire and forget — errors are logged but never surface to the caller
        try {
            $this->postToGa4($url, $payload);
        } catch (\Throwable $e) {
            log_error('[GA4] fireClickEvent failed', ['error' => $e->getMessage(), 'brand_id' => $brandId]);
        }
    }

    // -------------------------------------------------------------------------
    // Build GA4 Measurement Protocol payload
    // -------------------------------------------------------------------------

    private function buildPayload(int $brandId, string $eventName, array $params): array
    {
        return [
            'client_id' => 'meridian.' . $brandId,
            'events'    => [
                [
                    'name'   => $eventName,
                    'params' => array_merge($params, [
                        'engagement_time_msec' => 100,
                    ]),
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // cURL POST to GA4
    // -------------------------------------------------------------------------

    private function postToGa4(string $url, array $payload): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            log_error('[GA4] cURL error', ['error' => $error, 'url' => $url]);
            return null;
        }

        return $response ?: null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 4) return '••••';
        return str_repeat('•', max(0, strlen($secret) - 4)) . substr($secret, -4);
    }
}
