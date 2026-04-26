<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Aivo\Meridian\MeridianPublicationPipeline;
use Illuminate\Database\Capsule\Manager as DB;

class MeridianPublicationController
{
    /**
     * POST /api/meridian/publish/queue
     * Body: { "atom_id": "uuid" }
     *
     * Queues publication jobs for all destinations for a validated atom.
     * Also generates manual submission packages.
     *
     * Pass 3a: now dedupes — calling this twice for the same atom won't create
     * duplicate rows. Failed/retrying jobs get reset to queued.
     */
    public function queue(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $atomId = $body['atom_id'] ?? null;

        if (!$atomId) {
            http_response_code(400);
            json_response(['error' => 'atom_id is required.']);
            return;
        }

        // Confirm atom belongs to this agency
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
            $pipeline = new MeridianPublicationPipeline();
            $result   = $pipeline->queueAtom($atomId, []);

            json_response(['success' => true, 'data' => $result]);

        } catch (\RuntimeException $e) {
            http_response_code(422);
            json_response(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_error('[MeridianPublication] queue error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/publish/process
     * Body: { "job_id": "uuid" }
     *
     * Processes a single publication job immediately.
     * In production this would be called by a queue worker.
     * For testing/small volumes it can be called directly.
     */
    public function process(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $jobId = $body['job_id'] ?? null;

        if (!$jobId) {
            http_response_code(400);
            json_response(['error' => 'job_id is required.']);
            return;
        }

        // Confirm job belongs to this agency
        $job = DB::table('meridian_publication_jobs')
            ->where('id', $jobId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$job) {
            http_response_code(403);
            json_response(['error' => 'Job not found or access denied.']);
            return;
        }

        try {
            $pipeline = new MeridianPublicationPipeline();
            $result   = $pipeline->processJob($jobId);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[MeridianPublication] process error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * POST /api/meridian/publish/process-all
     * Body: { "atom_id": "uuid" }
     *
     * Processes ALL queued/retrying publication jobs for an atom in one call.
     * Sequential server-side execution with isolated error handling — one
     * destination failing won't stop the others.
     *
     * Pass 3a: new endpoint. Replaces the frontend's previous client-side
     * loop which had to re-queue jobs to get IDs (causing duplicates).
     */
    public function processAll(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $atomId = $body['atom_id'] ?? null;

        if (!$atomId) {
            http_response_code(400);
            json_response(['error' => 'atom_id is required.']);
            return;
        }

        // Confirm atom belongs to this agency
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
            $pipeline = new MeridianPublicationPipeline();
            $result   = $pipeline->processAllForAtom($atomId);

            json_response(['success' => true, 'data' => $result]);

        } catch (\Throwable $e) {
            log_error('[MeridianPublication] processAll error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['success' => false, 'error' => 'Internal server error.']);
        }
    }

    /**
     * GET /api/meridian/publish/status?atom_id=uuid
     *
     * Returns publication status for all jobs on an atom.
     *
     * Pass 3a: response now includes job IDs so the frontend can drive processing.
     */
    public function status(): void
    {
        $auth   = MeridianAuth::require();
        $atomId = $_GET['atom_id'] ?? null;

        if (!$atomId) {
            http_response_code(400);
            json_response(['error' => 'atom_id is required.']);
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

        $pipeline = new MeridianPublicationPipeline();
        $status   = $pipeline->getStatus($atomId);

        json_response(['success' => true, 'data' => $status]);
    }

    /**
     * POST /api/meridian/publish/manual-submitted
     * Body: { "package_id": "uuid" }
     *
     * Marks a manual submission package as submitted by the client.
     */
    public function markManualSubmitted(): void
    {
        $auth = MeridianAuth::require();
        $body = request_body();

        $packageId = $body['package_id'] ?? null;

        if (!$packageId) {
            http_response_code(400);
            json_response(['error' => 'package_id is required.']);
            return;
        }

        $package = DB::table('meridian_manual_submissions')
            ->where('id', $packageId)
            ->where('agency_id', $auth->agency_id)
            ->first();

        if (!$package) {
            http_response_code(403);
            json_response(['error' => 'Package not found or access denied.']);
            return;
        }

        DB::table('meridian_manual_submissions')
            ->where('id', $packageId)
            ->update([
                'status'       => 'submitted',
                'submitted_at' => now(),
            ]);

        json_response(['success' => true]);
    }
}
