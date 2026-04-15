<?php

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianFilterClassifier;

class MeridianClassifierController
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * POST /api/meridian/classify
     *
     * Body: { "audit_id": 123, "platform": "gemini" }
     *
     * Runs the filter classifier for one platform on a completed audit.
     * Returns the full classification with evidence gaps and briefs.
     */
    public function classify(): void
    {
        header('Content-Type: application/json');

        // Auth check — reuse existing Meridian session pattern
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorised']);
            return;
        }

        // Parse body
        $body = json_decode(file_get_contents('php://input'), true);
        $auditId  = isset($body['audit_id'])  ? (int) $body['audit_id']        : null;
        $platform = isset($body['platform'])  ? strtolower(trim($body['platform'])) : null;

        // Validate
        if (!$auditId || !$platform) {
            http_response_code(400);
            echo json_encode(['error' => 'audit_id and platform are required.']);
            return;
        }

        $validPlatforms = ['chatgpt', 'gemini', 'perplexity'];
        if (!in_array($platform, $validPlatforms, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'platform must be one of: chatgpt, gemini, perplexity.']);
            return;
        }

        // Confirm audit belongs to this agency
        if (!$this->auditBelongsToAgency($auditId, $user['agency_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this audit.']);
            return;
        }

        // Run classifier
        try {
            $classifier = new MeridianFilterClassifier($this->db);
            $result = $classifier->classify($auditId, $platform);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data'    => $result,
            ]);

        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            error_log('MeridianClassifierController error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Internal server error.',
            ]);
        }
    }

    /**
     * POST /api/meridian/classify/all
     *
     * Body: { "audit_id": 123 }
     *
     * Runs classifier for all three platforms on one audit.
     * Returns results keyed by platform.
     */
    public function classifyAll(): void
    {
        header('Content-Type: application/json');

        $user = $this->getAuthenticatedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorised']);
            return;
        }

        $body    = json_decode(file_get_contents('php://input'), true);
        $auditId = isset($body['audit_id']) ? (int) $body['audit_id'] : null;

        if (!$auditId) {
            http_response_code(400);
            echo json_encode(['error' => 'audit_id is required.']);
            return;
        }

        if (!$this->auditBelongsToAgency($auditId, $user['agency_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this audit.']);
            return;
        }

        $classifier = new MeridianFilterClassifier($this->db);
        $results    = [];
        $errors     = [];

        foreach (['chatgpt', 'gemini', 'perplexity'] as $platform) {
            try {
                $results[$platform] = $classifier->classify($auditId, $platform);
            } catch (\Throwable $e) {
                $errors[$platform] = $e->getMessage();
            }
        }

        http_response_code(200);
        echo json_encode([
            'success' => count($results) > 0,
            'data'    => $results,
            'errors'  => $errors,
        ]);
    }

    /**
     * GET /api/meridian/classify?audit_id=123
     *
     * Returns all saved classifications for an audit.
     */
    public function getClassifications(): void
    {
        header('Content-Type: application/json');

        $user = $this->getAuthenticatedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorised']);
            return;
        }

        $auditId = isset($_GET['audit_id']) ? (int) $_GET['audit_id'] : null;
        if (!$auditId) {
            http_response_code(400);
            echo json_encode(['error' => 'audit_id query parameter is required.']);
            return;
        }

        if (!$this->auditBelongsToAgency($auditId, $user['agency_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied.']);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id, platform, primary_filter, secondary_filters,
                    reasoning_stage, displacement_mechanism, confidence_score,
                    evidence_gaps, evidence_briefs, brand_story_frame,
                    reasoning_chain, dit_turn, t4_winner, created_at
             FROM meridian_filter_classifications
             WHERE audit_id = :audit_id
             ORDER BY created_at DESC"
        );
        $stmt->execute([':audit_id' => $auditId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decode JSONB fields
        foreach ($rows as &$row) {
            foreach (['secondary_filters', 'evidence_gaps', 'evidence_briefs', 'reasoning_chain'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $row[$field] = json_decode($row[$field], true);
                }
            }
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data'    => $rows,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getAuthenticatedUser(): ?array
    {
        // Reuses the existing Meridian session token pattern
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);

        if (!$token) return null;

        $stmt = $this->db->prepare(
            "SELECT u.id, u.agency_id, u.role
             FROM users u
             JOIN user_sessions s ON s.user_id = u.id
             WHERE s.token = :token
               AND s.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function auditBelongsToAgency(int $auditId, int $agencyId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM meridian_audits
             WHERE id = :audit_id AND agency_id = :agency_id"
        );
        $stmt->execute([
            ':audit_id'  => $auditId,
            ':agency_id' => $agencyId,
        ]);
        return (bool) $stmt->fetch();
    }
}
