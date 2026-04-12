<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianSuperadminController
 *
 * All endpoints require a valid superadmin session token.
 * Superadmin access is restricted to MASTER_EMAILS only.
 *
 * POST /api/meridian/admin/login                — Superadmin login
 * POST /api/meridian/admin/logout               — Superadmin logout
 * GET  /api/meridian/admin/agencies             — List all agencies
 * POST /api/meridian/admin/agencies/set-plan    — Update agency plan
 * POST /api/meridian/admin/agencies/suspend     — Suspend agency
 * POST /api/meridian/admin/agencies/unsuspend   — Unsuspend agency
 * GET  /api/meridian/admin/usage                — Platform usage overview
 * GET  /api/meridian/admin/audits               — All audits across agencies
 * POST /api/meridian/admin/audits/rerun         — Trigger audit re-run
 * GET  /api/meridian/admin/corpus               — Corpus contribution stats
 * GET  /api/meridian/admin/methodology          — Methodology versions
 * POST /api/meridian/admin/methodology/publish  — Publish new methodology version
 */
class MeridianSuperadminController
{
    // Superadmin emails — only these can access admin endpoints
    private const MASTER_EMAILS = ['paul@aivoedge.net', 'tim@aivoedge.net'];

    // Session duration: 8 hours
    private const SESSION_TTL = 28800;

    // ── POST /api/meridian/admin/login ───────────────────────────
    public function login(): void
    {
        $body     = request_body();
        $email    = isset($body['email'])    ? strtolower(trim($body['email'])) : null;
        $password = isset($body['password']) ? trim($body['password'])          : '';

        if (!$email || !$password) {
            http_response_code(400);
            json_response(['error' => 'Email and password are required.']);
            return;
        }

        if (!in_array($email, self::MASTER_EMAILS, true)) {
            http_response_code(403);
            json_response(['error' => 'Access denied.']);
            return;
        }

        try {
            // Validate against meridian_agency_users (superadmins are also agency users)
            $user = DB::table('meridian_agency_users')
                ->where('email', $email)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if (!$user || empty($user->password_hash) ||
                !password_verify($password, $user->password_hash)) {
                http_response_code(401);
                json_response(['error' => 'Incorrect email or password.']);
                return;
            }

            // Create superadmin session
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_TTL);

            DB::table('meridian_admin_sessions')->insert([
                'session_token'  => $token,
                'admin_email'    => $email,
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at'     => now(),
                'last_active_at' => now(),
                'expires_at'     => $expiresAt,
                'revoked'        => false,
            ]);

            json_response([
                'status' => 'ok',
                'token'  => $token,
                'email'  => $email,
                'expiresAt' => $expiresAt,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] login error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }

    // ── POST /api/meridian/admin/logout ──────────────────────────
    public function logout(): void
    {
        $session = $this->requireAdmin();

        DB::table('meridian_admin_sessions')
            ->where('session_token', $session->session_token)
            ->update([
                'revoked'        => true,
                'revoked_at'     => now(),
                'revoked_reason' => 'logout',
            ]);

        json_response(['status' => 'ok']);
    }

    // ── GET /api/meridian/admin/agencies ─────────────────────────
    public function agencies(): void
    {
        $this->requireAdmin();

        try {
            $agencies = DB::table('meridian_agencies as a')
                ->leftJoin('meridian_white_label_configs as wl', 'wl.agency_id', '=', 'a.id')
                ->whereNull('a.deleted_at')
                ->orderBy('a.created_at', 'desc')
                ->get([
                    'a.id',
                    'a.name',
                    'a.slug',
                    'a.contact_email',
                    'a.plan_type',
                    'a.plan_status',
                    'a.trial_ends_at',
                    'a.max_clients',
                    'a.max_brands',
                    'a.max_users',
                    'a.monthly_audit_allowance',
                    'a.created_at',
                    'wl.agency_display_name',
                    'wl.custom_domain',
                ]);

            // Enrich each agency with live usage counts
            $enriched = $agencies->map(function ($agency) {
                $agencyId = $agency->id;

                $clientCount = DB::table('meridian_clients')
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->count();

                $brandCount = DB::table('meridian_brands')
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->count();

                $userCount = DB::table('meridian_agency_users')
                    ->where('agency_id', $agencyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->count();

                $auditCount = DB::table('meridian_audits')
                    ->where('agency_id', $agencyId)
                    ->whereMonth('created_at', date('m'))
                    ->whereYear('created_at', date('Y'))
                    ->count();

                return [
                    'id'             => (int)$agency->id,
                    'name'           => $agency->name,
                    'slug'           => $agency->slug,
                    'contactEmail'   => $agency->contact_email,
                    'planType'       => $agency->plan_type,
                    'planStatus'     => $agency->plan_status,
                    'trialEndsAt'    => $agency->trial_ends_at,
                    'displayName'    => $agency->agency_display_name ?? $agency->name,
                    'customDomain'   => $agency->custom_domain,
                    'createdAt'      => $agency->created_at,
                    'limits' => [
                        'maxClients'           => (int)$agency->max_clients,
                        'maxBrands'            => (int)$agency->max_brands,
                        'maxUsers'             => (int)$agency->max_users,
                        'monthlyAuditAllowance'=> (int)$agency->monthly_audit_allowance,
                    ],
                    'usage' => [
                        'clients'        => $clientCount,
                        'brands'         => $brandCount,
                        'users'          => $userCount,
                        'auditsThisMonth'=> $auditCount,
                    ],
                ];
            });

            json_response([
                'status'   => 'ok',
                'total'    => count($enriched),
                'agencies' => $enriched,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] agencies error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/agencies/set-plan ───────────────
    public function setPlan(): void
    {
        $admin = $this->requireAdmin();
        $body  = request_body();

        $agencyId = (int)($body['agency_id'] ?? 0);
        $planType = trim($body['plan_type']  ?? '');

        $validPlans = ['fixed_starter', 'fixed_growth', 'fixed_pro', 'fixed_agency',
                       'enterprise_basic', 'enterprise_pro', 'enterprise_unlimited'];

        if (!$agencyId || !in_array($planType, $validPlans, true)) {
            http_response_code(422);
            json_response(['error' => 'Valid agency_id and plan_type are required.']);
            return;
        }

        try {
            $agency = DB::table('meridian_agencies')
                ->where('id', $agencyId)
                ->whereNull('deleted_at')
                ->first();

            if (!$agency) {
                http_response_code(404);
                json_response(['error' => 'Agency not found.']);
                return;
            }

            // Get plan limits from pricing_plans table
            $plan = DB::table('meridian_pricing_plans')
                ->where('plan_type', $planType)
                ->first();

            $updates = [
                'plan_type'  => $planType,
                'updated_at' => now(),
            ];

            if ($plan) {
                $updates['max_clients']              = $plan->max_clients;
                $updates['max_brands']               = $plan->max_brands;
                $updates['max_users']                = $plan->max_users;
                $updates['monthly_audit_allowance']  = $plan->monthly_audit_allowance;
            }

            DB::table('meridian_agencies')
                ->where('id', $agencyId)
                ->update($updates);

            $this->logAdminAction($admin->admin_email, 'agency.plan_changed', 'agency', $agencyId, [
                'from_plan' => $agency->plan_type,
                'to_plan'   => $planType,
            ]);

            json_response(['status' => 'ok', 'agencyId' => $agencyId, 'planType' => $planType]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] set-plan error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/agencies/suspend ────────────────
    public function suspend(): void
    {
        $admin    = $this->requireAdmin();
        $body     = request_body();
        $agencyId = (int)($body['agency_id'] ?? 0);
        $reason   = trim($body['reason'] ?? 'Suspended by administrator');

        if (!$agencyId) {
            http_response_code(422);
            json_response(['error' => 'agency_id is required.']);
            return;
        }

        try {
            DB::table('meridian_agencies')
                ->where('id', $agencyId)
                ->whereNull('deleted_at')
                ->update(['plan_status' => 'suspended', 'updated_at' => now()]);

            $this->logAdminAction($admin->admin_email, 'agency.suspended', 'agency', $agencyId, [
                'reason' => $reason,
            ]);

            json_response(['status' => 'ok', 'agencyId' => $agencyId]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] suspend error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/agencies/unsuspend ──────────────
    public function unsuspend(): void
    {
        $admin    = $this->requireAdmin();
        $body     = request_body();
        $agencyId = (int)($body['agency_id'] ?? 0);

        if (!$agencyId) {
            http_response_code(422);
            json_response(['error' => 'agency_id is required.']);
            return;
        }

        try {
            DB::table('meridian_agencies')
                ->where('id', $agencyId)
                ->whereNull('deleted_at')
                ->update(['plan_status' => 'active', 'updated_at' => now()]);

            $this->logAdminAction($admin->admin_email, 'agency.unsuspended', 'agency', $agencyId, []);

            json_response(['status' => 'ok', 'agencyId' => $agencyId]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] unsuspend error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/admin/usage ────────────────────────────
    public function usage(): void
    {
        $this->requireAdmin();

        try {
            $currentMonth = date('Y-m');

            $totalAgencies = DB::table('meridian_agencies')
                ->whereNull('deleted_at')
                ->count();

            $activeAgencies = DB::table('meridian_agencies')
                ->whereNull('deleted_at')
                ->where('plan_status', 'active')
                ->count();

            $trialAgencies = DB::table('meridian_agencies')
                ->whereNull('deleted_at')
                ->where('plan_status', 'trial')
                ->count();

            $totalBrands = DB::table('meridian_brands')
                ->whereNull('deleted_at')
                ->count();

            $totalAuditsThisMonth = DB::table('meridian_audits')
                ->whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->count();

            $auditsByStatus = DB::table('meridian_audits')
                ->whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->select(DB::raw('status, COUNT(*) as count'))
                ->groupBy('status')
                ->get();

            $corpusContributions = DB::table('meridian_corpus_contributions')
                ->count();

            $corpusThisMonth = DB::table('meridian_corpus_contributions')
                ->whereMonth('contributed_at', date('m'))
                ->whereYear('contributed_at', date('Y'))
                ->count();

            // Agencies approaching audit limit (>80% used)
            $agenciesAtRisk = DB::table('meridian_agencies as a')
                ->whereNull('a.deleted_at')
                ->where('a.plan_status', '!=', 'suspended')
                ->get(['a.id', 'a.name', 'a.monthly_audit_allowance'])
                ->filter(function ($agency) {
                    $used = DB::table('meridian_audits')
                        ->where('agency_id', $agency->id)
                        ->whereMonth('created_at', date('m'))
                        ->whereYear('created_at', date('Y'))
                        ->count();
                    $agency->audits_used = $used;
                    return $agency->monthly_audit_allowance > 0 &&
                           ($used / $agency->monthly_audit_allowance) >= 0.8;
                })
                ->values();

            json_response([
                'status' => 'ok',
                'period' => $currentMonth,
                'agencies' => [
                    'total'     => $totalAgencies,
                    'active'    => $activeAgencies,
                    'trial'     => $trialAgencies,
                    'suspended' => $totalAgencies - $activeAgencies - $trialAgencies,
                ],
                'brands' => [
                    'total' => $totalBrands,
                ],
                'audits' => [
                    'thisMonth' => $totalAuditsThisMonth,
                    'byStatus'  => $auditsByStatus,
                ],
                'corpus' => [
                    'total'     => $corpusContributions,
                    'thisMonth' => $corpusThisMonth,
                ],
                'alerts' => [
                    'agenciesApproachingLimit' => $agenciesAtRisk,
                ],
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] usage error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/admin/audits ───────────────────────────
    public function audits(): void
    {
        $this->requireAdmin();

        $limit  = min((int)($_GET['limit']  ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);
        $status = $_GET['status'] ?? null;

        try {
            $query = DB::table('meridian_audits as au')
                ->join('meridian_agencies as ag', 'ag.id', '=', 'au.agency_id')
                ->join('meridian_brands as b',    'b.id',  '=', 'au.brand_id')
                ->leftJoin('meridian_clients as c', 'c.id', '=', 'au.client_id')
                ->whereNull('ag.deleted_at');

            if ($status) {
                $query->where('au.status', $status);
            }

            $total = $query->count();

            $audits = $query
                ->orderBy('au.created_at', 'desc')
                ->limit($limit)
                ->offset($offset)
                ->get([
                    'au.id',
                    'au.status',
                    'au.audit_type',
                    'au.created_at',
                    'au.completed_at',
                    'au.error_message',
                    'ag.name as agency_name',
                    'b.name as brand_name',
                    'c.name as client_name',
                ]);

            json_response([
                'status' => 'ok',
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
                'audits' => $audits->map(fn($a) => [
                    'id'          => (int)$a->id,
                    'status'      => $a->status,
                    'auditType'   => $a->audit_type,
                    'agencyName'  => $a->agency_name,
                    'brandName'   => $a->brand_name,
                    'clientName'  => $a->client_name,
                    'createdAt'   => $a->created_at,
                    'completedAt' => $a->completed_at,
                    'error'       => $a->error_message,
                ]),
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] audits error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/audits/rerun ────────────────────
    public function rerun(): void
    {
        $admin   = $this->requireAdmin();
        $body    = request_body();
        $auditId = (int)($body['audit_id'] ?? 0);

        if (!$auditId) {
            http_response_code(422);
            json_response(['error' => 'audit_id is required.']);
            return;
        }

        try {
            $audit = DB::table('meridian_audits')->find($auditId);

            if (!$audit) {
                http_response_code(404);
                json_response(['error' => 'Audit not found.']);
                return;
            }

            // Reset audit to queued state for re-processing
            DB::table('meridian_audits')
                ->where('id', $auditId)
                ->update([
                    'status'        => 'queued',
                    'error_message' => null,
                    'completed_at'  => null,
                    'updated_at'    => now(),
                ]);

            $this->logAdminAction($admin->admin_email, 'audit.rerun', 'audit', $auditId, []);

            json_response(['status' => 'ok', 'auditId' => $auditId, 'newStatus' => 'queued']);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] rerun error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/admin/corpus ───────────────────────────
    public function corpus(): void
    {
        $this->requireAdmin();

        try {
            $total = DB::table('meridian_corpus_contributions')->count();

            $byPlatform = DB::table('meridian_corpus_contributions')
                ->select(DB::raw('platform, COUNT(*) as count'))
                ->groupBy('platform')
                ->orderBy('count', 'desc')
                ->get();

            $byCategory = DB::table('meridian_corpus_contributions')
                ->select(DB::raw('category, COUNT(*) as count'))
                ->groupBy('category')
                ->orderBy('count', 'desc')
                ->limit(20)
                ->get();

            $byDitTurn = DB::table('meridian_corpus_contributions')
                ->select(DB::raw('dit_turn, COUNT(*) as count'))
                ->groupBy('dit_turn')
                ->orderBy('dit_turn')
                ->get();

            $monthlyTrend = DB::table('meridian_corpus_contributions')
                ->select(DB::raw("TO_CHAR(contributed_at, 'YYYY-MM') as month, COUNT(*) as count"))
                ->groupBy(DB::raw("TO_CHAR(contributed_at, 'YYYY-MM')"))
                ->orderBy('month', 'desc')
                ->limit(12)
                ->get();

            $findings = DB::table('meridian_research_findings')
                ->select('id', 'title', 'finding_type', 'status', 'evidence_count', 'published_at')
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();

            json_response([
                'status' => 'ok',
                'corpus' => [
                    'total'        => $total,
                    'byPlatform'   => $byPlatform,
                    'byCategory'   => $byCategory,
                    'byDitTurn'    => $byDitTurn,
                    'monthlyTrend' => $monthlyTrend,
                ],
                'findings' => $findings,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] corpus error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/admin/methodology ──────────────────────
    public function methodology(): void
    {
        $this->requireAdmin();

        try {
            $deployments = DB::table('meridian_methodology_deployments')
                ->orderBy('deployed_at', 'desc')
                ->get();

            $current = $deployments->first();

            // Count agencies per methodology version
            $versionUsage = DB::table('meridian_audits as au')
                ->select(DB::raw('methodology_version, COUNT(DISTINCT agency_id) as agency_count, COUNT(*) as audit_count'))
                ->groupBy('methodology_version')
                ->orderBy('methodology_version', 'desc')
                ->get();

            json_response([
                'status'          => 'ok',
                'currentVersion'  => $current->version ?? null,
                'deployments'     => $deployments,
                'versionUsage'    => $versionUsage,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] methodology error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/methodology/publish ─────────────
    public function publishMethodology(): void
    {
        $admin = $this->requireAdmin();
        $body  = request_body();

        $version     = trim($body['version']     ?? '');
        $description = trim($body['description'] ?? '');
        $changes     = $body['changes']           ?? [];

        if (!$version) {
            http_response_code(422);
            json_response(['error' => 'version is required.']);
            return;
        }

        try {
            // Check version doesn't already exist
            $existing = DB::table('meridian_methodology_deployments')
                ->where('version', $version)
                ->first();

            if ($existing) {
                http_response_code(409);
                json_response(['error' => 'Version ' . $version . ' already exists.']);
                return;
            }

            DB::table('meridian_methodology_deployments')->insert([
                'version'      => $version,
                'description'  => $description,
                'changes'      => json_encode($changes),
                'deployed_by'  => $admin->admin_email,
                'deployed_at'  => now(),
                'created_at'   => now(),
            ]);

            $this->logAdminAction($admin->admin_email, 'methodology.published', 'methodology', 0, [
                'version'     => $version,
                'description' => $description,
            ]);

            json_response(['status' => 'ok', 'version' => $version]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] publish methodology error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── Private: requireAdmin ────────────────────────────────────
    // Validates superadmin session token from Authorization header.
    // Returns the session row or halts with 401/403.
    private function requireAdmin(): object
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token      = str_starts_with($authHeader, 'Bearer ')
            ? trim(substr($authHeader, 7)) : '';

        if (!$token) {
            http_response_code(401);
            json_response(['error' => 'Admin session token required.']);
            exit;
        }

        $session = DB::table('meridian_admin_sessions')
            ->where('session_token', $token)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            http_response_code(401);
            json_response(['error' => 'Invalid or expired admin session.']);
            exit;
        }

        if (!in_array($session->admin_email, self::MASTER_EMAILS, true)) {
            http_response_code(403);
            json_response(['error' => 'Access denied.']);
            exit;
        }

        // Refresh last_active_at
        DB::table('meridian_admin_sessions')
            ->where('session_token', $token)
            ->update(['last_active_at' => now()]);

        return $session;
    }

    // ── Private: logAdminAction ──────────────────────────────────
    private function logAdminAction(
        string $adminEmail,
        string $action,
        string $entityType,
        int    $entityId,
        array  $metadata
    ): void {
        try {
            DB::table('meridian_audit_log')->insert([
                'agency_id'   => 0, // superadmin actions use agency_id 0
                'user_id'     => 0,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'metadata'    => json_encode(array_merge($metadata, ['admin' => $adminEmail])),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — log but don't fail the request
            log_error('[Meridian Admin] audit log failed', ['error' => $e->getMessage()]);
        }
    }
}
