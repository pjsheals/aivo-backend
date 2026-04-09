<?php

declare(strict_types=1);

namespace Aivo\Controllers;

class AdminController
{
    private const SUPERADMIN_EMAILS = [
        'paul@aivoedge.net',
        'tim@aivoedge.net',
        'paul@aivoevidentia.com',
    ];

    // ── Auth guard ───────────────────────────────────────────────
    // FIX (Bug 2): getallheaders() is unreliable on Railway's nginx/PHP-FPM
    // stack — the Authorization header is dropped or not forwarded by the
    // FastCGI proxy. Use $_SERVER['HTTP_AUTHORIZATION'] which is set directly
    // by the SAPI regardless of web-server type. REDIRECT_HTTP_AUTHORIZATION
    // covers Apache mod_rewrite edge-cases where the header is rewritten.
    // This matches the pattern already used in OptimizeController::changePassword().
    private function requireSuperadmin(): void
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
             ?? '';

        if (!str_starts_with($auth, 'Bearer ')) {
            abort(403, 'Forbidden');
        }

        $token = trim(substr($auth, 7));

        if (!$token) {
            abort(403, 'Forbidden');
        }

        $user = \Illuminate\Database\Capsule\Manager::table('users')
            ->where('session_token', $token)
            ->where('session_expires', '>', date('Y-m-d H:i:s'))
            ->first();

        if (!$user || !in_array(strtolower($user->email ?? ''), self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Forbidden');
        }
    }

    // ── GET /api/admin/users ─────────────────────────────────────
    // FIX (Bug 3): Wrapped entire method in try-catch. Previously any DB
    // column mismatch (e.g. after a schema migration that added/removed a
    // column) threw an uncaught exception, causing Railway to return a 500
    // whose body is not valid JSON. The frontend's res.json() then threw,
    // the catch block showed "Failed to load users", and the admin panel
    // appeared blank.
    public function getUsers(): void
    {
        $this->requireSuperadmin();

        try {
            $rows = \Illuminate\Database\Capsule\Manager::table('users')
                ->select('id','name','email','company','plan','beta_access','tests_used','probe_brand','probe_category','created_at','last_login_at')
                ->orderBy('created_at', 'desc')
                ->get();

            $users = $rows->map(fn($u) => [
                'id'             => $u->id,
                'name'           => $u->name           ?? '',
                'email'          => $u->email          ?? '',
                'company'        => $u->company        ?? '',
                'plan'           => $u->plan           ?? 'free',
                'beta_access'    => (bool)($u->beta_access ?? false),
                'tests_used'     => (int)($u->tests_used  ?? 0),
                'probe_brand'    => $u->probe_brand    ?? '',
                'probe_category' => $u->probe_category ?? '',
                'registered_at'  => $u->created_at     ?? null,
                'last_login_at'  => $u->last_login_at  ?? null,
            ])->toArray();

            json_response(['users' => $users]);

        } catch (\Throwable $e) {
            log_error('[AIVO] admin getUsers error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to load users — ' . $e->getMessage()]);
        }
    }

    // ── GET /api/admin/stats ─────────────────────────────────────
    // FIX (Bug 3): Added outer try-catch around all user-table queries.
    // Previously only the probe_events query was wrapped; any failure in the
    // users queries propagated uncaught.
    //
    // FIX (Bug 4): Added per-tier breakdowns (growth_users, pro_users,
    // agency_users) and stub fields (tests_week, tests_month, mrr) that
    // adminFetchStats() in the frontend expects. Without these, the Overview
    // panel's tier counters and MRR card stayed at '—' even when the endpoint
    // responded successfully.
    public function getStats(): void
    {
        $this->requireSuperadmin();

        try {
            $yesterday  = date('Y-m-d H:i:s', strtotime('-1 day'));
            $weekAgo    = date('Y-m-d H:i:s', strtotime('-7 days'));
            $monthStart = date('Y-m-01 00:00:00');
            $todayStart = date('Y-m-d 00:00:00');

            $db = \Illuminate\Database\Capsule\Manager::table('users');

            $total_users  = (clone $db)->count();
            $growth_users = (clone $db)->where('plan', 'growth')->count();
            $pro_users    = (clone $db)->where('plan', 'pro')->count();
            $agency_users = (clone $db)->where('plan', 'agency')->count();
            $paid_users   = $growth_users + $pro_users + $agency_users;
            $free_users   = (clone $db)->where('plan', 'free')->where('beta_access', false)->count();
            $beta_users   = (clone $db)->where('beta_access', true)->count();
            $total_tests  = (int)((clone $db)->sum('tests_used') ?? 0);
            $active_today = (clone $db)->where('last_login_at', '>=', $yesterday)->count();
            $new_today    = (clone $db)->where('created_at', '>=', $todayStart)->count();

            // Tier MRR rates — keep in sync with planMRR() in index.html
            $MRR_RATES = ['growth' => 199, 'pro' => 499, 'agency' => 999];
            $mrr = ($growth_users * $MRR_RATES['growth'])
                 + ($pro_users    * $MRR_RATES['pro'])
                 + ($agency_users * $MRR_RATES['agency']);

            // Category breakdown from users table
            $cats = (clone $db)
                ->select('probe_category as category')
                ->selectRaw('COUNT(*) as cnt')
                ->whereNotNull('probe_category')
                ->where('probe_category', '<>', '')
                ->groupBy('probe_category')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(8)
                ->get()
                ->map(fn($r) => ['category' => $r->category, 'cnt' => $r->cnt])
                ->toArray();

            // Tests from probe_events — time-bucketed
            $tests_today = 0;
            $tests_week  = null;
            $tests_month = null;
            try {
                $pe = \Illuminate\Database\Capsule\Manager::table('probe_events');
                $tests_today = (clone $pe)->where('created_at', '>=', $todayStart)->count();
                $tests_week  = (clone $pe)->where('created_at', '>=', $weekAgo)->count();
                $tests_month = (clone $pe)->where('created_at', '>=', $monthStart)->count();
            } catch (\Throwable $e) {
                // probe_events table may not exist in all environments
                log_error('[AIVO] admin stats probe_events query failed', ['error' => $e->getMessage()]);
                $tests_today = $active_today; // reasonable fallback
            }

            json_response([
                // Totals
                'total_users'  => $total_users,
                'paid_users'   => $paid_users,
                'free_users'   => $free_users,
                'beta_users'   => $beta_users,
                'total_tests'  => $total_tests,
                'active_today' => $active_today,
                'new_today'    => $new_today,
                'mrr'          => $mrr,
                // Per-tier (used by adminFetchStats() uc-growth/pro/agency counters)
                'growth_users' => $growth_users,
                'pro_users'    => $pro_users,
                'agency_users' => $agency_users,
                // Time-bucketed test counts
                'tests_today'  => $tests_today,
                'tests_week'   => $tests_week,
                'tests_month'  => $tests_month,
                // Category breakdown
                'categories'   => $cats,
            ]);

        } catch (\Throwable $e) {
            log_error('[AIVO] admin getStats error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to load stats — ' . $e->getMessage()]);
        }
    }

    // ── POST /api/admin/set-plan ─────────────────────────────────
    public function setPlan(): void
    {
        $this->requireSuperadmin();

        $body       = request_body();
        $email      = isset($body['email']) ? strtolower(trim($body['email'])) : null;
        $plan       = $body['plan']        ?? 'free';
        $betaAccess = (bool)($body['beta_access'] ?? false);

        if (!$email) { abort(422, 'Email required'); }

        try {
            $updated = \Illuminate\Database\Capsule\Manager::table('users')
                ->where('email', $email)
                ->update(['plan' => $plan, 'beta_access' => $betaAccess ? 1 : 0]);

            if ($updated === 0) {
                abort(404, 'User not found');
            }

            json_response(['success' => true]);

        } catch (\Throwable $e) {
            log_error('[AIVO] admin setPlan error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to update plan — ' . $e->getMessage()]);
        }
    }

    // ── POST /api/admin/delete-user ──────────────────────────────
    public function deleteUser(): void
    {
        $this->requireSuperadmin();

        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email) { abort(422, 'Email required'); }

        if (in_array($email, self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Cannot delete a superadmin account');
        }

        try {
            $row = \Illuminate\Database\Capsule\Manager::table('users')
                ->where('email', $email)
                ->first();

            if (!$row) { abort(404, 'User not found'); }

            \Illuminate\Database\Capsule\Manager::table('diagnostic_runs')
                ->where('user_id', $row->id)->delete();
            \Illuminate\Database\Capsule\Manager::table('users')
                ->where('id', $row->id)->delete();

            json_response(['success' => true]);

        } catch (\Throwable $e) {
            log_error('[AIVO] admin deleteUser error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Failed to delete user — ' . $e->getMessage()]);
        }
    }
}
