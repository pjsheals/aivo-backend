<?php

declare(strict_types=1);

namespace Aivo\Controllers;

/**
 * Admin-only endpoints — protected by session token belonging to a superadmin email.
 *
 * Routes (add to routes/api.php):
 *   GET  /api/admin/users   → AdminController@getUsers
 *   GET  /api/admin/stats   → AdminController@getStats
 */
class AdminController
{
    private const SUPERADMIN_EMAILS = [
        'paul@aivoedge.net',
        'tim@aivoedge.net',
        'paul@aivoevidentia.com',
    ];

    // ── Auth gate ────────────────────────────────────────────────
    // Reads Bearer token from Authorization header, looks up the
    // matching user row, and checks their email is in SUPERADMIN_EMAILS.
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
    // Returns all user rows, newest first.
    // Never returns password_hash, session_token, or reset_token.
    public function getUsers(): void
    {
        $this->requireSuperadmin();

        $rows = \Illuminate\Database\Capsule\Manager::table('users')
            ->select('id','name','email','company','plan','beta_access','tests_used','probe_brand','probe_category','registered_at','last_login_at')
            ->orderBy('registered_at', 'desc')
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
            'registered_at'  => $u->registered_at  ?? null,
            'last_login_at'  => $u->last_login_at  ?? null,
        ])->toArray();

        json_response(['users' => $users]);
    }

    // ── GET /api/admin/stats ─────────────────────────────────────
    // Returns aggregate counts for the admin overview panel.
    public function getStats(): void
    {
        $this->requireSuperadmin();

        $db = \Illuminate\Database\Capsule\Manager::table('users');

        $total_users  = (clone $db)->count();
        $paid_users   = (clone $db)->where('plan', 'paid')->count();
        $free_users   = (clone $db)->where('plan', 'free')->count();
        $beta_users   = (clone $db)->where('beta_access', true)->count();
        $total_tests  = (int)((clone $db)->sum('tests_used') ?? 0);
        $active_today = (clone $db)->where('last_login_at', '>=', now()->subDay())->count();

        $tests_today = 0;
        try {
            $tests_today = \Illuminate\Database\Capsule\Manager::table('probe_events')
                ->where('created_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable $e) {
            $tests_today = $active_today;
        }

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
            'total_users'  => $total_users,
            'paid_users'   => $paid_users,
            'free_users'   => $free_users,
            'beta_users'   => $beta_users,
            'total_tests'  => $total_tests,
            'tests_today'  => $tests_today,
            'active_today' => $active_today,
            'categories'   => $cats,
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
        $superadmins = ['paul@aivoedge.net', 'tim@aivoedge.net', 'paul@aivoevidentia.com'];
        if (in_array($email, $superadmins, true)) {
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
