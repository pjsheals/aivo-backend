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
            ->where('session_expires', '>', date('Y-m-d H:i:s'))
            ->first();

        if (!$user || !in_array(strtolower($user->email ?? ''), self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Forbidden');
        }
    }

    public function getUsers(): void
    {
        $this->requireSuperadmin();

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
    }

    public function getStats(): void
    {
        $this->requireSuperadmin();

        // Use raw PHP date strings — no Carbon dependency
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $todayStart = date('Y-m-d 00:00:00');

        $db = \Illuminate\Database\Capsule\Manager::table('users');

        $total_users  = (clone $db)->count();
        $paid_users   = (clone $db)->whereIn('plan', ['growth', 'pro', 'agency', 'paid'])->count();
        $free_users   = (clone $db)->where('plan', 'free')->where('beta_access', false)->count();
        $beta_users   = (clone $db)->where('beta_access', true)->count();
        $total_tests  = (int)((clone $db)->sum('tests_used') ?? 0);
        $active_today = (clone $db)->where('last_login_at', '>=', $yesterday)->count();
        $new_today    = (clone $db)->where('created_at', '>=', $todayStart)->count();

        $tests_today = 0;
        try {
            $tests_today = \Illuminate\Database\Capsule\Manager::table('probe_events')
                ->where('created_at', '>=', $yesterday)
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
            'new_today'    => $new_today,
            'categories'   => $cats,
        ]);
    }

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

    public function deleteUser(): void
    {
        $this->requireSuperadmin();

        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email) { abort(422, 'Email required'); }

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
