<?php

declare(strict_types=1);

namespace Aivo\Controllers;

/**
 * Admin-only endpoints — protected by session token belonging to a superadmin email.
 *
 * Routes (routes/api.php):
 *   GET  /api/admin/users   → AdminController@getUsers
 *   GET  /api/admin/stats   → AdminController@getStats
 *   POST /api/admin/set-plan    → AdminController@setPlan
 *   POST /api/admin/delete-user → AdminController@deleteUser
 */
class AdminController
{
    private const SUPERADMIN_EMAILS = [
        'paul@aivoedge.net',
        'tim@aivoedge.net',
        'paul@aivoevidentia.com',
    ];

    // Plans that represent a paid seat (legacy 'paid' kept for safety during transition)
    private const PAID_PLANS = ['growth', 'pro', 'agency', 'paid'];

    // MRR per plan in USD — single source of truth
    private const PLAN_MRR = [
        'growth' => 199,
        'pro'    => 395,
        'agency' => 695,
        'paid'   => 199, // legacy — treat same as growth
    ];

    // ── Auth gate ────────────────────────────────────────────────
    private function requireSuperadmin(): void
    {
        $headers = getallheaders();
        $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!str_starts_with($auth, 'Bearer ')) {
            abort(403, 'Forbidden');
        }

        $token = trim(substr($auth, 7));

        if (!$token) {
            abort(403, 'Forbidden');
        }

        $user = \Illuminate\Database\Capsule\Manager::table('users')
            ->where('session_token', $token)
            ->where('session_expires', '>', now())
            ->first();

        if (!$user || !in_array(strtolower($user->email ?? ''), self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Forbidden');
        }
    }

    // ── GET /api/admin/users ─────────────────────────────────────
    // Returns all user rows newest-first, with per-user recent test
    // activity counts (7-day and 30-day) fetched in two bulk queries.
    // Never returns password_hash, session_token, or reset_token.
    public function getUsers(): void
    {
        $this->requireSuperadmin();

        $rows = \Illuminate\Database\Capsule\Manager::table('users')
            ->select([
                'id', 'name', 'email', 'company', 'plan', 'beta_access',
                'tests_used', 'probe_brand', 'probe_category',
                'created_at', 'last_login_at',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // ── Bulk-fetch recent test counts per user (2 queries, not N+1) ──
        $counts7  = [];
        $counts30 = [];
        try {
            $raw7 = \Illuminate\Database\Capsule\Manager::table('probe_events')
                ->select('user_id')
                ->selectRaw('COUNT(*) as cnt')
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('user_id')
                ->get();
            foreach ($raw7 as $r) {
                $counts7[(int)$r->user_id] = (int)$r->cnt;
            }

            $raw30 = \Illuminate\Database\Capsule\Manager::table('probe_events')
                ->select('user_id')
                ->selectRaw('COUNT(*) as cnt')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('user_id')
                ->get();
            foreach ($raw30 as $r) {
                $counts30[(int)$r->user_id] = (int)$r->cnt;
            }
        } catch (\Throwable $e) {
            // probe_events table may not exist yet — degrade gracefully
        }

        $users = $rows->map(function ($u) use ($counts7, $counts30) {
            return [
                'id'                 => $u->id,
                'name'               => $u->name            ?? '',
                'email'              => $u->email           ?? '',
                'company'            => $u->company         ?? '',
                'plan'               => $u->plan            ?? 'free',
                'beta_access'        => (bool)($u->beta_access ?? false),
                'tests_used'         => (int)($u->tests_used  ?? 0),
                'tests_last_7_days'  => $counts7[(int)$u->id]  ?? 0,
                'tests_last_30_days' => $counts30[(int)$u->id] ?? 0,
                'probe_brand'        => $u->probe_brand     ?? '',
                'probe_category'     => $u->probe_category  ?? '',
                'registered_at'      => $u->created_at      ?? null,
                'last_login_at'      => $u->last_login_at   ?? null,
            ];
        })->toArray();

        json_response(['users' => $users]);
    }

    // ── GET /api/admin/stats ─────────────────────────────────────
    // Returns aggregate counts for the admin overview panel.
    // Counts all paid tiers correctly; computes real MRR server-side.
    public function getStats(): void
    {
        $this->requireSuperadmin();

        $db = \Illuminate\Database\Capsule\Manager::table('users');

        $total_users  = (clone $db)->count();
        $beta_users   = (clone $db)->where('beta_access', true)->count();
        $free_users   = (clone $db)->where('plan', 'free')
                                   ->where('beta_access', false)->count();

        // Per-tier paid counts (legacy 'paid' mapped to growth)
        $growth_users = (clone $db)->whereIn('plan', ['growth', 'paid'])->count();
        $pro_users    = (clone $db)->where('plan', 'pro')->count();
        $agency_users = (clone $db)->where('plan', 'agency')->count();
        $paid_users   = $growth_users + $pro_users + $agency_users;

        // Server-side MRR calculation — single source of truth
        $mrr = ($growth_users * self::PLAN_MRR['growth'])
             + ($pro_users    * self::PLAN_MRR['pro'])
             + ($agency_users * self::PLAN_MRR['agency']);

        $total_tests  = (int)((clone $db)->sum('tests_used') ?? 0);
        $active_today = (clone $db)->where('last_login_at', '>=', now()->subDay())->count();

        // New signups today
        $new_today = (clone $db)->where('created_at', '>=', now()->startOfDay())->count();

        // Test event counts — degrade gracefully if probe_events doesn't exist
        $tests_today = 0;
        $tests_week  = 0;
        $tests_month = 0;
        try {
            $pe = \Illuminate\Database\Capsule\Manager::table('probe_events');
            $tests_today = (clone $pe)->where('created_at', '>=', now()->subDay())->count();
            $tests_week  = (clone $pe)->where('created_at', '>=', now()->subDays(7))->count();
            $tests_month = (clone $pe)->where('created_at', '>=', now()->subDays(30))->count();
        } catch (\Throwable $e) {
            $tests_today = $active_today;
        }

        // Top categories by user count
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

        json_response([
            'total_users'   => $total_users,
            'paid_users'    => $paid_users,
            'free_users'    => $free_users,
            'beta_users'    => $beta_users,
            'growth_users'  => $growth_users,
            'pro_users'     => $pro_users,
            'agency_users'  => $agency_users,
            'mrr'           => $mrr,
            'total_tests'   => $total_tests,
            'tests_today'   => $tests_today,
            'tests_week'    => $tests_week,
            'tests_month'   => $tests_month,
            'active_today'  => $active_today,
            'new_today'     => $new_today,
            'categories'    => $cats,
        ]);
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

        // Validate plan is a known value
        $validPlans = ['free', 'growth', 'pro', 'agency'];
        if (!in_array($plan, $validPlans, true)) {
            abort(422, 'Invalid plan: ' . $plan);
        }

        $updated = \Illuminate\Database\Capsule\Manager::table('users')
            ->where('email', $email)
            ->update(['plan' => $plan, 'beta_access' => $betaAccess ? 1 : 0]);

        if ($updated === 0) {
            abort(404, 'User not found');
        }

        json_response(['success' => true]);
    }

    // ── POST /api/admin/delete-user ──────────────────────────────
    public function deleteUser(): void
    {
        $this->requireSuperadmin();

        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email) { abort(422, 'Email required'); }

        // Prevent superadmins from deleting themselves
        if (in_array($email, self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Cannot delete a superadmin account');
        }

        $row = \Illuminate\Database\Capsule\Manager::table('users')
            ->where('email', $email)
            ->first();

        if (!$row) { abort(404, 'User not found'); }

        \Illuminate\Database\Capsule\Manager::table('diagnostic_runs')
            ->where('user_id', $row->id)->delete();
        \Illuminate\Database\Capsule\Manager::table('users')
            ->where('id', $row->id)->delete();

        json_response(['success' => true]);
    }
}
