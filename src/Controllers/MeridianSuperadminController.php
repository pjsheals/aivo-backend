<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use Aivo\Meridian\MeridianCorpusAnalyser;

/**
 * MeridianSuperadminController
 *
 * POST /api/meridian/admin/login                — Superadmin login
 * POST /api/meridian/admin/logout               — Superadmin logout
 * GET  /api/meridian/admin/agencies             — List all agencies
 * POST /api/meridian/admin/agencies/create      — Create new agency + admin user
 * POST /api/meridian/admin/agencies/set-plan    — Update agency plan
 * POST /api/meridian/admin/agencies/suspend     — Suspend agency
 * POST /api/meridian/admin/agencies/unsuspend   — Unsuspend agency
 * GET  /api/meridian/admin/usage                — Platform usage overview
 * GET  /api/meridian/admin/audits               — All audits across agencies
 * POST /api/meridian/admin/audits/rerun         — Trigger audit re-run
 * GET  /api/meridian/admin/corpus               — Corpus contribution stats
 * GET  /api/meridian/admin/methodology          — Methodology versions
 * POST /api/meridian/admin/methodology/publish  — Publish new methodology version
 * POST /api/meridian/admin/run-migration        — Run DB migrations against live DB
 */
class MeridianSuperadminController
{
    private const MASTER_EMAILS = ['paul@aivoedge.net', 'tim@aivoedge.net'];
    private const SESSION_TTL   = 28800; // 8 hours

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

            json_response(['status' => 'ok', 'token' => $token, 'email' => $email, 'expiresAt' => $expiresAt]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] login error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/logout ──────────────────────────
    public function logout(): void
    {
        $session = $this->requireAdmin();
        DB::table('meridian_admin_sessions')
            ->where('session_token', $session->session_token)
            ->update(['revoked' => true, 'revoked_at' => now(), 'revoked_reason' => 'logout']);
        json_response(['status' => 'ok']);
    }

    // ── POST /api/meridian/admin/agencies/create ─────────────────
    public function createAgency(): void
    {
        $admin = $this->requireAdmin();
        $body  = request_body();

        $agencyName   = trim($body['agency_name']   ?? '');
        $contactEmail = strtolower(trim($body['contact_email'] ?? ''));
        $planType     = trim($body['plan_type']      ?? 'fixed_starter');
        $firstName    = trim($body['first_name']     ?? '');
        $lastName     = trim($body['last_name']      ?? '');
        $userEmail    = strtolower(trim($body['email'] ?? ''));
        $password     = $body['password']            ?? '';

        if (!$agencyName || !$userEmail || !$password || !$firstName) {
            http_response_code(422);
            json_response(['error' => 'Agency name, first name, email and password are required.']);
            return;
        }
        if (strlen($password) < 8) {
            http_response_code(422);
            json_response(['error' => 'Password must be at least 8 characters.']);
            return;
        }

        $existing = DB::table('meridian_agency_users')
            ->where('email', $userEmail)->whereNull('deleted_at')->first();
        if ($existing) {
            http_response_code(409);
            json_response(['error' => 'An account with this email already exists.']);
            return;
        }

        $validPlans = ['fixed_starter', 'fixed_growth', 'fixed_agency', 'enterprise_metered'];
        if (!in_array($planType, $validPlans, true)) $planType = 'fixed_starter';

        try {
            DB::beginTransaction();

            $plan = DB::table('meridian_pricing_plans')->where('plan_type', $planType)->first();
            $slug = $this->generateSlug($agencyName);

            $agencyId = DB::table('meridian_agencies')->insertGetId([
                'deployment_id'           => 1,
                'name'                    => $agencyName,
                'slug'                    => $slug,
                'contact_email'           => $contactEmail ?: $userEmail,
                'billing_email'           => $contactEmail ?: $userEmail,
                'plan_type'               => $planType,
                'plan_status'             => 'active',
                'max_clients'             => $plan->max_clients ?? null,
                'max_brands'              => $plan->max_brands ?? null,
                'max_users'               => $plan->max_users ?? null,
                'monthly_audit_allowance' => $plan->monthly_audit_allowance ?? null,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            DB::table('meridian_white_label_configs')->insert([
                'agency_id'           => $agencyId,
                'agency_display_name' => $agencyName,
                'show_aivo_branding'  => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            $userId = DB::table('meridian_agency_users')->insertGetId([
                'agency_id'     => $agencyId,
                'email'         => $userEmail,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'role'          => 'admin',
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $this->logAdminAction($admin->admin_email, 'agency.created', 'agency', $agencyId, [
                'agency_name' => $agencyName,
                'email'       => $userEmail,
                'plan_type'   => $planType,
            ]);

            DB::commit();

            json_response([
                'status'   => 'ok',
                'agencyId' => $agencyId,
                'userId'   => $userId,
                'slug'     => $slug,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            log_error('[Meridian Admin] createAgency error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error: ' . $e->getMessage()]);
        }
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
                ->get(['a.id','a.name','a.slug','a.contact_email','a.plan_type','a.plan_status',
                       'a.trial_ends_at','a.max_clients','a.max_brands','a.max_users',
                       'a.monthly_audit_allowance','a.created_at','wl.agency_display_name','wl.custom_domain']);

            $enriched = $agencies->map(function ($agency) {
                $id = $agency->id;
                return [
                    'id'           => (int)$id,
                    'name'         => $agency->name,
                    'slug'         => $agency->slug,
                    'contactEmail' => $agency->contact_email,
                    'planType'     => $agency->plan_type,
                    'planStatus'   => $agency->plan_status,
                    'trialEndsAt'  => $agency->trial_ends_at,
                    'displayName'  => $agency->agency_display_name ?? $agency->name,
                    'customDomain' => $agency->custom_domain,
                    'createdAt'    => $agency->created_at,
                    'limits' => [
                        'maxClients'            => (int)$agency->max_clients,
                        'maxBrands'             => (int)$agency->max_brands,
                        'maxUsers'              => (int)$agency->max_users,
                        'monthlyAuditAllowance' => (int)$agency->monthly_audit_allowance,
                    ],
                    'usage' => [
                        'clients'         => DB::table('meridian_clients')->where('agency_id',$id)->whereNull('deleted_at')->count(),
                        'brands'          => DB::table('meridian_brands')->where('agency_id',$id)->whereNull('deleted_at')->count(),
                        'users'           => DB::table('meridian_agency_users')->where('agency_id',$id)->where('is_active',true)->whereNull('deleted_at')->count(),
                        'auditsThisMonth' => DB::table('meridian_audits')->where('agency_id',$id)->whereMonth('created_at',date('m'))->whereYear('created_at',date('Y'))->count(),
                    ],
                ];
            });

            json_response(['status' => 'ok', 'total' => count($enriched), 'agencies' => $enriched]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] agencies error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/agencies/set-plan ───────────────
    public function setPlan(): void
    {
        $admin    = $this->requireAdmin();
        $body     = request_body();
        $agencyId = (int)($body['agency_id'] ?? 0);
        $planType = trim($body['plan_type']   ?? '');

        $validPlans = ['fixed_starter','fixed_growth','fixed_agency','enterprise_metered'];
        if (!$agencyId || !in_array($planType, $validPlans, true)) {
            http_response_code(422);
            json_response(['error' => 'Valid agency_id and plan_type are required.']);
            return;
        }

        try {
            $agency = DB::table('meridian_agencies')->where('id',$agencyId)->whereNull('deleted_at')->first();
            if (!$agency) { http_response_code(404); json_response(['error'=>'Agency not found.']); return; }

            $plan    = DB::table('meridian_pricing_plans')->where('plan_type',$planType)->first();
            $updates = ['plan_type' => $planType, 'updated_at' => now()];
            if ($plan) {
                $updates['max_clients']             = $plan->max_clients;
                $updates['max_brands']              = $plan->max_brands;
                $updates['max_users']               = $plan->max_users;
                $updates['monthly_audit_allowance'] = $plan->monthly_audit_allowance;
            }
            DB::table('meridian_agencies')->where('id',$agencyId)->update($updates);
            $this->logAdminAction($admin->admin_email, 'agency.plan_changed', 'agency', $agencyId, ['from'=>$agency->plan_type,'to'=>$planType]);
            json_response(['status' => 'ok']);
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
        if (!$agencyId) { http_response_code(422); json_response(['error'=>'agency_id required.']); return; }

        try {
            DB::table('meridian_agencies')->where('id',$agencyId)->whereNull('deleted_at')
                ->update(['plan_status'=>'suspended','updated_at'=>now()]);
            $this->logAdminAction($admin->admin_email, 'agency.suspended', 'agency', $agencyId, []);
            json_response(['status' => 'ok']);
        } catch (\Throwable $e) {
            log_error('[Meridian Admin] suspend error', ['error'=>$e->getMessage()]);
            http_response_code(500); json_response(['error'=>'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/agencies/unsuspend ──────────────
    public function unsuspend(): void
    {
        $admin    = $this->requireAdmin();
        $body     = request_body();
        $agencyId = (int)($body['agency_id'] ?? 0);
        if (!$agencyId) { http_response_code(422); json_response(['error'=>'agency_id required.']); return; }

        try {
            DB::table('meridian_agencies')->where('id',$agencyId)->whereNull('deleted_at')
                ->update(['plan_status'=>'active','updated_at'=>now()]);
            $this->logAdminAction($admin->admin_email, 'agency.unsuspended', 'agency', $agencyId, []);
            json_response(['status' => 'ok']);
        } catch (\Throwable $e) {
            log_error('[Meridian Admin] unsuspend error', ['error'=>$e->getMessage()]);
            http_response_code(500); json_response(['error'=>'Server error.']);
        }
    }

    // ── GET /api/meridian/admin/usage ────────────────────────────
    public function usage(): void
    {
        $this->requireAdmin();

        try {
            $month = date('m');
            $year  = date('Y');

            $total     = DB::table('meridian_agencies')->whereNull('deleted_at')->count();
            $active    = DB::table('meridian_agencies')->whereNull('deleted_at')->where('plan_status','active')->count();
            $trial     = DB::table('meridian_agencies')->whereNull('deleted_at')->where('plan_status','trial')->count();
            $suspended = DB::table('meridian_agencies')->whereNull('deleted_at')->where('plan_status','suspended')->count();
            $brands    = DB::table('meridian_brands')->whereNull('deleted_at')->count();

            $auditsThisMonth = DB::table('meridian_audits')
                ->whereMonth('created_at', $month)->whereYear('created_at', $year)->count();

            $byStatus = DB::table('meridian_audits')
                ->whereMonth('created_at', $month)->whereYear('created_at', $year)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->map(fn($r) => ['status' => $r->status, 'count' => (int)$r->count])
                ->toArray();

            $corpusTotal     = 0;
            $corpusThisMonth = 0;
            $corpusByPlatform = [];
            try {
                $corpusTotal     = DB::table('meridian_corpus_contributions')->count();
                $corpusThisMonth = DB::table('meridian_corpus_contributions')
                    ->whereMonth('created_at', $month)->whereYear('created_at', $year)->count();
                $corpusByPlatform = DB::table('meridian_corpus_contributions')
                    ->select('platform', DB::raw('COUNT(*) as count'))
                    ->groupBy('platform')
                    ->orderByDesc('count')
                    ->get()
                    ->map(fn($r) => ['platform' => $r->platform ?? 'unknown', 'count' => (int)$r->count])
                    ->toArray();
            } catch (\Throwable $_) {}

            $agenciesApproachingLimit = [];
            try {
                $agencies = DB::table('meridian_agencies')
                    ->whereNull('deleted_at')
                    ->where('plan_status', 'active')
                    ->whereNotNull('monthly_audit_allowance')
                    ->where('monthly_audit_allowance', '>', 0)
                    ->get(['id','name','monthly_audit_allowance']);

                foreach ($agencies as $ag) {
                    $used = DB::table('meridian_audits')
                        ->where('agency_id', $ag->id)
                        ->whereMonth('created_at', $month)
                        ->whereYear('created_at', $year)
                        ->count();
                    $pct = ($used / $ag->monthly_audit_allowance) * 100;
                    if ($pct >= 80) {
                        $agenciesApproachingLimit[] = [
                            'name'                    => $ag->name,
                            'audits_used'             => $used,
                            'monthly_audit_allowance' => (int)$ag->monthly_audit_allowance,
                            'pct'                     => round($pct),
                        ];
                    }
                }
            } catch (\Throwable $_) {}

            json_response([
                'status'   => 'ok',
                'period'   => date('F Y'),
                'agencies' => ['total' => $total, 'active' => $active, 'trial' => $trial, 'suspended' => $suspended],
                'brands'   => ['total' => $brands],
                'audits'   => ['thisMonth' => $auditsThisMonth, 'byStatus' => $byStatus],
                'corpus'   => ['total' => $corpusTotal, 'thisMonth' => $corpusThisMonth, 'byPlatform' => $corpusByPlatform],
                'alerts'   => ['agenciesApproachingLimit' => $agenciesApproachingLimit],
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
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);
        $status = $_GET['status'] ?? null;

        try {
            $query = DB::table('meridian_audits as au')
                ->join('meridian_agencies as ag','ag.id','=','au.agency_id')
                ->join('meridian_brands as b','b.id','=','au.brand_id')
                ->leftJoin('meridian_clients as c','c.id','=','au.client_id')
                ->whereNull('ag.deleted_at');
            if ($status) $query->where('au.status', $status);

            $total  = $query->count();
            $audits = $query->orderBy('au.created_at','desc')->limit($limit)->offset($offset)
                ->get(['au.id','au.status','au.audit_type','au.created_at','au.completed_at',
                       'au.error_message','ag.name as agency_name','b.name as brand_name','c.name as client_name']);

            json_response([
                'status' => 'ok', 'total' => $total, 'limit' => $limit, 'offset' => $offset,
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
        if (!$auditId) { http_response_code(422); json_response(['error'=>'audit_id required.']); return; }

        try {
            $audit = DB::table('meridian_audits')->find($auditId);
            if (!$audit) { http_response_code(404); json_response(['error'=>'Audit not found.']); return; }

            DB::table('meridian_audits')->where('id',$auditId)
                ->update(['status'=>'queued','error_message'=>null,'completed_at'=>null,'updated_at'=>now()]);
            $this->logAdminAction($admin->admin_email,'audit.rerun','audit',$auditId,[]);
            json_response(['status'=>'ok','auditId'=>$auditId]);
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
            $total      = 0;
            $byCategory = [];
            $byDitTurn  = [];
            $byPlatform = [];

            try {
                $total = DB::table('meridian_corpus_contributions')->count();

                $byCategory = DB::table('meridian_corpus_contributions')
                    ->select('category', DB::raw('COUNT(*) as count'))
                    ->groupBy('category')->orderByDesc('count')->limit(20)->get()
                    ->map(fn($r) => ['category' => $r->category ?? 'unknown', 'count' => (int)$r->count])
                    ->toArray();

                $byDitTurn = DB::table('meridian_corpus_contributions')
                    ->select('dit_turn', DB::raw('COUNT(*) as count'))
                    ->groupBy('dit_turn')->orderBy('dit_turn')->get()
                    ->map(fn($r) => ['dit_turn' => $r->dit_turn, 'count' => (int)$r->count])
                    ->toArray();

                $byPlatform = DB::table('meridian_corpus_contributions')
                    ->select('platform', DB::raw('COUNT(*) as count'))
                    ->groupBy('platform')->orderByDesc('count')->get()
                    ->map(fn($r) => ['platform' => $r->platform ?? 'unknown', 'count' => (int)$r->count])
                    ->toArray();

            } catch (\Throwable $_) {}

            $findings = [];
            try {
                $findings = DB::table('meridian_research_findings')
                    ->orderByDesc('created_at')
                    ->get(['title','finding_type','status','evidence_count','created_at'])
                    ->map(fn($f) => [
                        'title'          => $f->title,
                        'finding_type'   => $f->finding_type,
                        'status'         => $f->status,
                        'evidence_count' => (int)$f->evidence_count,
                        'createdAt'      => $f->created_at,
                    ])
                    ->toArray();
            } catch (\Throwable $_) {}

            json_response([
                'status'   => 'ok',
                'corpus'   => [
                    'total'      => $total,
                    'byCategory' => $byCategory,
                    'byDitTurn'  => $byDitTurn,
                    'byPlatform' => $byPlatform,
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
                ->orderBy('deployed_at','desc')->get();
            json_response([
                'status'         => 'ok',
                'currentVersion' => $deployments->first()->version ?? null,
                'deployments'    => $deployments,
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
        $admin   = $this->requireAdmin();
        $body    = request_body();
        $version = trim($body['version'] ?? '');
        if (!$version) { http_response_code(422); json_response(['error'=>'version required.']); return; }

        try {
            if (DB::table('meridian_methodology_deployments')->where('version',$version)->first()) {
                http_response_code(409);
                json_response(['error'=>'Version already exists.']);
                return;
            }
            DB::table('meridian_methodology_deployments')->insert([
                'version'     => $version,
                'description' => trim($body['description'] ?? ''),
                'changes'     => json_encode($body['changes'] ?? []),
                'deployed_by' => $admin->admin_email,
                'deployed_at' => now(),
                'created_at'  => now(),
            ]);
            json_response(['status' => 'ok', 'version' => $version]);
        } catch (\Throwable $e) {
            log_error('[Meridian Admin] methodology/publish error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── GET /api/meridian/admin/model-watch ──────────────────────
    public function modelWatch(): void
    {
        $this->requireAdmin();

        try {
            $analyser = new \Aivo\Meridian\MeridianCorpusAnalyser();

            $today         = date('Y-m-d');
            $snapshotToday = DB::table('meridian_corpus_snapshots')
                ->where('snapshot_date', $today)->exists();

            $analysisResult = null;
            if (!$snapshotToday) {
                $analysisResult = $analyser->runDailyAnalysis();
            }

            $alerts         = $analyser->getAlerts(50);
            $platformStatus = $analyser->getPlatformStatus();
            $unackCount     = DB::table('meridian_model_behaviour_alerts')
                ->where('acknowledged', false)->count();

            json_response([
                'status'         => 'ok',
                'unacknowledged' => (int)$unackCount,
                'platform_status'=> array_values($platformStatus),
                'alerts'         => $alerts,
                'analysis_ran'   => $analysisResult !== null,
                'new_alerts'     => $analysisResult['alerts_fired'] ?? 0,
                'last_checked'   => $today,
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] model-watch error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error: ' . $e->getMessage()]);
        }
    }

    // ── POST /api/meridian/admin/alerts/acknowledge ──────────────
    public function acknowledgeAlert(): void
    {
        $admin   = $this->requireAdmin();
        $body    = request_body();
        $alertId = (int)($body['alert_id'] ?? 0);

        if (!$alertId) {
            http_response_code(422);
            json_response(['error' => 'alert_id required.']);
            return;
        }

        try {
            $updated = DB::table('meridian_model_behaviour_alerts')
                ->where('id', $alertId)
                ->where('acknowledged', false)
                ->update([
                    'acknowledged'    => true,
                    'acknowledged_at' => now(),
                    'acknowledged_by' => $admin->admin_email,
                ]);

            if (!$updated) {
                http_response_code(404);
                json_response(['error' => 'Alert not found or already acknowledged.']);
                return;
            }

            json_response(['status' => 'ok', 'alert_id' => $alertId]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] acknowledge alert error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error.']);
        }
    }

    // ── POST /api/meridian/admin/model-watch/run ─────────────────
    public function runModelWatch(): void
    {
        $this->requireAdmin();

        try {
            $analyser = new \Aivo\Meridian\MeridianCorpusAnalyser();
            $today    = date('Y-m-d');
            DB::table('meridian_corpus_snapshots')->where('snapshot_date', $today)->delete();

            $result = $analyser->runDailyAnalysis();

            json_response([
                'status'       => 'ok',
                'alerts_fired' => $result['alerts_fired'],
                'new_alerts'   => $result['new_alerts'],
                'ran_at'       => now(),
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian Admin] run model-watch error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error: ' . $e->getMessage()]);
        }
    }

    // ── POST /api/meridian/admin/run-migration ───────────────────
    public function runMigration(): void
    {
        $this->requireAdmin();

        $statements = [
            // Stage 1–3: existing column additions
            "ALTER TABLE meridian_probe_runs ADD COLUMN IF NOT EXISTS probe_type VARCHAR(30)",
            "ALTER TABLE meridian_probe_runs ADD COLUMN IF NOT EXISTS displacement_criteria TEXT",
            "ALTER TABLE meridian_filter_classifications ADD COLUMN IF NOT EXISTS probe_type VARCHAR(30)",
            "ALTER TABLE meridian_filter_classifications ADD COLUMN IF NOT EXISTS handoff_turn SMALLINT",
            "ALTER TABLE meridian_filter_classifications ADD COLUMN IF NOT EXISTS survival_gap SMALLINT",
            "ALTER TABLE meridian_filter_classifications ADD COLUMN IF NOT EXISTS displacement_criteria TEXT",
            "ALTER TABLE meridian_atoms ADD COLUMN IF NOT EXISTS probe_type VARCHAR(30)",
            "ALTER TABLE meridian_atoms ADD COLUMN IF NOT EXISTS approval_status VARCHAR(20) DEFAULT 'pending_approval'",
            "ALTER TABLE meridian_atoms ADD COLUMN IF NOT EXISTS approval_notes TEXT",
            "ALTER TABLE meridian_atoms ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ",
            "ALTER TABLE meridian_atoms ADD COLUMN IF NOT EXISTS approved_by INTEGER",

            // Stage 4 (M9): Brand Intelligence Packages table
            "CREATE TABLE IF NOT EXISTS meridian_brand_packages (
                id BIGSERIAL PRIMARY KEY,
                brand_id BIGINT NOT NULL,
                agency_id BIGINT NOT NULL,
                platform VARCHAR(20) NOT NULL,
                file_content JSONB NOT NULL,
                atom_count SMALLINT DEFAULT 0,
                evidence_count SMALLINT DEFAULT 0,
                generated_at TIMESTAMPTZ DEFAULT NOW(),
                version VARCHAR(10) DEFAULT '1.0',
                created_at TIMESTAMPTZ DEFAULT NOW(),
                updated_at TIMESTAMPTZ DEFAULT NOW()
            )",
            "CREATE INDEX IF NOT EXISTS idx_mbp_brand_platform ON meridian_brand_packages(brand_id, platform)",

            // Stage 6 (M8): Turn delta on reprobe results
            "ALTER TABLE meridian_reprobe_results ADD COLUMN IF NOT EXISTS turn_delta_data JSONB",
        ];

        $results = [];
        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
                $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'ok'];
            } catch (\Throwable $e) {
                $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        json_response(['success' => true, 'results' => $results]);
    }

    // ── Private helpers ──────────────────────────────────────────
    private function requireAdmin(): object
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token      = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : '';

        if (!$token) { http_response_code(401); json_response(['error'=>'Admin session token required.']); exit; }

        $session = DB::table('meridian_admin_sessions')
            ->where('session_token', $token)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) { http_response_code(401); json_response(['error'=>'Invalid or expired admin session.']); exit; }
        if (!in_array($session->admin_email, self::MASTER_EMAILS, true)) { http_response_code(403); json_response(['error'=>'Access denied.']); exit; }

        DB::table('meridian_admin_sessions')->where('session_token',$token)->update(['last_active_at'=>now()]);
        return $session;
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
        $base = $slug;
        $i    = 1;
        while (DB::table('meridian_agencies')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function logAdminAction(string $adminEmail, string $action, string $entityType, int $entityId, array $metadata): void
    {
        try {
            DB::table('meridian_audit_log')->insert([
                'agency_id'   => 0,
                'user_id'     => 0,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'metadata'    => json_encode(array_merge($metadata, ['admin' => $adminEmail])),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            log_error('[Meridian Admin] audit log failed', ['error' => $e->getMessage()]);
        }
    }
}
