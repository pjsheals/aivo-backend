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

        $pdo  = db();
        $stmt = $pdo->prepare(
            'SELECT email FROM users
              WHERE session_token = ?
                AND session_expires > NOW()
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !in_array(strtolower($row['email']), self::SUPERADMIN_EMAILS, true)) {
            abort(403, 'Forbidden');
        }
    }

    // ── GET /api/admin/users ─────────────────────────────────────
    // Returns all user rows, newest first.
    // Never returns password_hash, session_token, or reset_token.
    public function getUsers(): void
    {
        $this->requireSuperadmin();

        $pdo  = db();
        $stmt = $pdo->query(
            'SELECT
               id,
               name,
               email,
               company,
               plan,
               beta_access,
               tests_used,
               probe_brand,
               probe_category,
               registered_at,
               last_login_at
             FROM users
             ORDER BY registered_at DESC'
        );
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($users as &$u) {
            $u['beta_access'] = (bool) $u['beta_access'];
            $u['tests_used']  = (int)  ($u['tests_used'] ?? 0);
        }
        unset($u);

        json_response(['users' => $users]);
    }

    // ── GET /api/admin/stats ─────────────────────────────────────
    // Returns aggregate counts for the admin overview panel.
    public function getStats(): void
    {
        $this->requireSuperadmin();

        $pdo = db();

        // Core user aggregates
        $totals = $pdo->query(
            "SELECT
               COUNT(*)                                                    AS total_users,
               SUM(CASE WHEN plan = 'paid'    THEN 1 ELSE 0 END)          AS paid_users,
               SUM(CASE WHEN plan = 'free'    THEN 1 ELSE 0 END)          AS free_users,
               SUM(CASE WHEN beta_access = TRUE THEN 1 ELSE 0 END)        AS beta_users,
               COALESCE(SUM(tests_used), 0)                               AS total_tests,
               SUM(CASE WHEN last_login_at >= NOW() - INTERVAL '1 day'
                        THEN 1 ELSE 0 END)                                AS active_today
             FROM users"
        )->fetch(\PDO::FETCH_ASSOC);

        // Tests run today — probe_results table may not exist yet, so wrap safely
        $tests_today = 0;
        try {
            $tests_today = (int) $pdo->query(
                "SELECT COUNT(*) FROM probe_results
                  WHERE created_at >= NOW() - INTERVAL '1 day'"
            )->fetchColumn();
        } catch (\Throwable $e) {
            // Table doesn't exist yet — fall back to tests_used sum
            $tests_today = (int) $pdo->query(
                "SELECT COALESCE(SUM(tests_used), 0) FROM users
                  WHERE last_login_at >= NOW() - INTERVAL '1 day'"
            )->fetchColumn();
        }

        // Category breakdown from probe_brand/probe_category columns
        $cats = $pdo->query(
            "SELECT
               probe_category  AS category,
               COUNT(*)        AS cnt
             FROM users
             WHERE probe_category IS NOT NULL
               AND probe_category <> ''
             GROUP BY probe_category
             ORDER BY cnt DESC
             LIMIT 8"
        )->fetchAll(\PDO::FETCH_ASSOC);

        json_response([
            'total_users'  => (int) $totals['total_users'],
            'paid_users'   => (int) $totals['paid_users'],
            'free_users'   => (int) $totals['free_users'],
            'beta_users'   => (int) $totals['beta_users'],
            'total_tests'  => (int) $totals['total_tests'],
            'tests_today'  => $tests_today,
            'active_today' => (int) $totals['active_today'],
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

        $pdo  = db();
        $stmt = $pdo->prepare('UPDATE users SET plan = ?, beta_access = ? WHERE email = ?');
        $stmt->execute([$plan, $betaAccess ? 1 : 0, $email]);

        if ($stmt->rowCount() === 0) {
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

        $pdo = db();

        // Delete diagnostic runs first (foreign key)
        $user = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $user->execute([$email]);
        $row = $user->fetch(\PDO::FETCH_ASSOC);

        if (!$row) { abort(404, 'User not found'); }

        $pdo->prepare('DELETE FROM diagnostic_runs WHERE user_id = ?')->execute([$row['id']]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$row['id']]);

        json_response(['success' => true]);
    }
}
