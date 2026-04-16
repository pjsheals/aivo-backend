<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianAtomGenerator;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianAtomController
{
    /**
     * POST /api/meridian/atoms/generate
     * Body: { brand_id, audit_id, filter_type, model_variant }
     */
    public function generate(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId      = isset($body['brand_id'])     ? (int)$body['brand_id']              : null;
        $auditId      = isset($body['audit_id'])      ? (int)$body['audit_id']              : null;
        $filterType   = isset($body['filter_type'])   ? strtoupper(trim($body['filter_type'])) : null;
        $modelVariant = isset($body['model_variant']) ? strtolower(trim($body['model_variant'])) : 'universal';

        if (!$brandId || !$auditId || !$filterType) {
            http_response_code(400);
            json_response(['error' => 'brand_id, audit_id, and filter_type are required.']);
            return;
        }

        if (!preg_match('/^T[0-8]$/', $filterType)) {
            http_response_code(400);
            json_response(['error' => 'filter_type must be T0–T8.']);
            return;
        }

        if (!in_array($modelVariant, ['gemini', 'chatgpt', 'perplexity', 'universal'], true)) {
            http_response_code(400);
            json_response(['error' => 'model_variant must be gemini, chatgpt, perplexity, or universal.']);
            return;
        }

        // Confirm brand belongs to this agency
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
            $generator = new MeridianAtomGenerator();
            $result    = $generator->generate($brandId, $auditId, $filterType, $modelVariant);

            json_response(['success' => true, 'data' => $result]);

        } catch (\RuntimeException $e) {
            http_response_code(422);
            json_response(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_error('[MeridianAtom] generate error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/atoms/generate-all
     * Body: { brand_id, audit_id, filter_type }
     * Generates all four model variants for one filter gap.
     */
    public function generateAll(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $brandId    = isset($body['brand_id'])   ? (int)$body['brand_id']                : null;
        $auditId    = isset($body['audit_id'])    ? (int)$body['audit_id']                : null;
        $filterType = isset($body['filter_type']) ? strtoupper(trim($body['filter_type'])) : null;

        if (!$brandId || !$auditId || !$filterType) {
            http_response_code(400);
            json_response(['error' => 'brand_id, audit_id, and filter_type are required.']);
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
            $generator = new MeridianAtomGenerator();
            $result    = $generator->generateAllVariants($brandId, $auditId, $filterType);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[MeridianAtom] generateAll error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * GET /api/meridian/atoms?brand_id=1&audit_id=26
     * Returns all atoms grouped by filter_type and model_variant.
     */
    public function list(): void
    {
        $auth    = MeridianAuth::require();
        $brandId = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
        $auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : null;

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

        $query = DB::table('meridian_atoms')
            ->where('brand_id', $brandId)
            ->orderBy('filter_type')
            ->orderBy('model_variant');

        if ($auditId) $query->where('audit_id', $auditId);

        $rows = $query->get();

        // Group by filter_type
        $grouped = [];
        foreach ($rows as $row) {
            $filter = $row->filter_type;
            if (!isset($grouped[$filter])) $grouped[$filter] = [];
            $grouped[$filter][] = [
                'id'               => $row->id,
                'atom_identifier'  => $row->atom_identifier,
                'filter_type'      => $row->filter_type,
                'model_variant'    => $row->model_variant,
                'reasoning_stage'  => $row->reasoning_stage,
                'entity'           => $row->entity,
                'claim'            => $row->claim,
                'validation_score' => $row->validation_score,
                'status'           => $row->status,
                'zenodo_doi'       => $row->zenodo_doi,
                'created_at'       => $row->created_at,
            ];
        }

        json_response(['success' => true, 'data' => $grouped]);
    }

    /**
     * GET /api/meridian/atoms/detail?id={uuid}
     * Returns full atom including raw_atom JSON.
     */
    public function detail(): void
    {
        $auth = MeridianAuth::require();
        $id   = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            json_response(['error' => 'id is required.']);
            return;
        }

        $atom = DB::table('meridian_atoms')->where('id', $id)->first();

        if (!$atom) {
            http_response_code(404);
            json_response(['error' => 'Atom not found.']);
            return;
        }

        // Confirm brand belongs to this agency
        $brand = DB::table('meridian_brands')
            ->where('id', $atom->brand_id)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$brand) {
            http_response_code(403);
            json_response(['error' => 'Access denied.']);
            return;
        }

        json_response([
            'success' => true,
            'data'    => [
                'id'                    => $atom->id,
                'atom_identifier'       => $atom->atom_identifier,
                'filter_type'           => $atom->filter_type,
                'model_variant'         => $atom->model_variant,
                'reasoning_stage'       => $atom->reasoning_stage,
                'entity'                => $atom->entity,
                'claim'                 => $atom->claim,
                'conversational_query'  => $atom->conversational_query,
                'conversational_answer' => $atom->conversational_answer,
                'citations'             => json_decode($atom->citations     ?? '[]', true),
                'attributes'            => json_decode($atom->attributes    ?? '[]', true),
                'trust_tier'            => $atom->trust_tier,
                'related_queries'       => json_decode($atom->related_queries ?? '[]', true),
                'validation_score'      => $atom->validation_score,
                'validation_notes'      => $atom->validation_notes,
                'status'                => $atom->status,
                'raw_atom'              => json_decode($atom->raw_atom ?? '{}', true),
                'zenodo_doi'            => $atom->zenodo_doi,
                'created_at'            => $atom->created_at,
            ],
        ]);
    }
}
