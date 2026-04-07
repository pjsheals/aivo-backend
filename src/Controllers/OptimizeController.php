<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Models\User;
use Aivo\Models\DiagnosticRun;

class OptimizeController
{
    // ── POST /api/register ───────────────────────────────────────
    public function register(): void
    {
        $body = request_body();

        $email    = isset($body['email'])   ? strtolower(trim($body['email'])) : null;
        $name     = $body['name']            ?? '';
        $company  = $body['company']         ?? '';
        $plan     = $body['plan']            ?? 'free';
        $beta     = (bool)($body['beta_access']    ?? false);
        $brand    = $body['probe_brand']     ?? '';
        $cat      = $body['probe_category']  ?? '';
        $password = trim($body['password']   ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Valid email required');
        }

        // Hash password with bcrypt — never stored plain-text
        $passwordHash = $password
            ? password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])
            : null;

        try {
            $user = User::where('email', $email)->first();

            if ($user) {
                $user->name           = $name    ?: $user->name;
                $user->company        = $company ?: $user->company;
                $user->plan           = $plan;
                $user->beta_access    = $beta;
                $user->probe_brand    = $brand   ?: $user->probe_brand;
                $user->probe_category = $cat     ?: $user->probe_category;
                // Only update password if a new one was provided
                if ($passwordHash) {
                    $user->password_hash = $passwordHash;
                }
                $user->save();
            } else {
                $user = User::create([
                    'email'          => $email,
                    'name'           => $name    ?: explode('@', $email)[0],
                    'company'        => $company,
                    'plan'           => $plan,
                    'beta_access'    => $beta,
                    'probe_brand'    => $brand,
                    'probe_category' => $cat,
                    'tests_used'     => 0,
                    'password_hash'  => $passwordHash,
                ]);
            }

            // Create session and return token so frontend can store it
            $token = $this->createSession($user);

            json_response([
                'status' => 'ok',
                'token'  => $token,
                'user'   => $this->safeUser($user),
            ]);

        } catch (\Throwable $e) {
            log_error('[AIVO] register error', ['error' => $e->getMessage()]);
            json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    // ── POST /api/login ──────────────────────────────────────────
    public function login(): void
    {
        $body     = request_body();
        $email    = isset($body['email'])    ? strtolower(trim($body['email']))  : null;
        $password = isset($body['password']) ? trim($body['password'])           : '';

        if (!$email || !$password) {
            http_response_code(400);
            json_response(['error' => 'Email and password are required.']);
            return;
        }

        try {
            $user = User::where('email', $email)->first();

            // Return same error whether email not found or password wrong
            // — prevents email enumeration attacks
            if (!$user || empty($user->password_hash) ||
                !password_verify($password, $user->password_hash)) {
                http_response_code(401);
                json_response(['error' => 'Incorrect email or password.']);
                return;
            }

            // Update last login
            $user->last_login_at = now();
            $user->save();

            $token = $this->createSession($user);

            // Fetch latest diagnostic for session restoration
            $latest = DiagnosticRun::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->first();

            $diagPayload = null;
            if ($latest) {
                $diagPayload = [
                    'brand'       => $latest->brand,
                    'category'    => $latest->category,
                    'results'     => $latest->results,
                    'completedAt' => (string)$latest->created_at,
                ];
            }

            json_response([
                'success'           => true,
                'token'             => $token,
                'user'              => $this->safeUser($user),
                'latestDiagnostic'  => $diagPayload,
            ]);

        } catch (\Throwable $e) {
            log_error('[AIVO] login error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }


    // ── POST /api/change-password ────────────────────────────────
    public function changePassword(): void
    {
        // Validate Bearer token from Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token      = str_replace('Bearer ', '', $authHeader);
        $user       = $this->getUserByToken($token);

        if (!$user) {
            http_response_code(401);
            json_response(['error' => 'Session expired. Please sign in again.']);
            return;
        }

        $body            = request_body();
        $currentPassword = trim($body['current_password'] ?? '');
        $newPassword     = trim($body['new_password']     ?? '');

        if (strlen($newPassword) < 8) {
            http_response_code(400);
            json_response(['error' => 'New password must be at least 8 characters.']);
            return;
        }

        // Verify current password against bcrypt hash
        if (!empty($user->password_hash) &&
            !password_verify($currentPassword, $user->password_hash)) {
            http_response_code(401);
            json_response(['error' => 'Current password is incorrect.']);
            return;
        }

        try {
            $user->password_hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $user->save();

            // Issue a fresh session token after password change
            $newToken = $this->createSession($user);

            json_response(['success' => true, 'token' => $newToken]);

        } catch (\Throwable $e) {
            log_error('[AIVO] change-password error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }


    // ── POST /api/sync-diagnostic ────────────────────────────────
    public function syncDiagnostic(): void
    {
        $body = request_body();

        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;
        $diag  = $body['diagnostic'] ?? null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Valid email required');
        }
        if (!$diag || !isset($diag['brand'], $diag['results'])) {
            abort(422, 'diagnostic.brand and diagnostic.results required');
        }

        try {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'email' => $email,
                    'name'  => explode('@', $email)[0],
                    'plan'  => 'free',
                ]);
            }

            DiagnosticRun::create([
                'user_id'  => $user->id,
                'brand'    => $diag['brand'],
                'category' => $diag['category'] ?? '',
                'results'  => $diag['results'],
            ]);

            $runs = DiagnosticRun::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get();

            if ($runs->count() > 10) {
                $runs->slice(10)->each(fn($r) => $r->delete());
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] sync-diagnostic error', ['error' => $e->getMessage()]);
            json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    // ── GET /api/user-data?email=... ─────────────────────────────
    public function getUserData(): void
    {
        $email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(400, 'Valid email required');
        }

        try {
            $user = User::where('email', $email)->first();

            if (!$user) {
                json_response(null);
                return;
            }

            $latest = DiagnosticRun::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->first();

            $diagPayload = null;
            if ($latest) {
                $diagPayload = [
                    'brand'       => $latest->brand,
                    'category'    => $latest->category,
                    'results'     => $latest->results,
                    'completedAt' => (string)$latest->created_at,
                ];
            }

            json_response([
                'status'           => 'ok',
                'plan'             => $user->plan,
                'betaAccess'       => (bool)$user->beta_access,
                'latestDiagnostic' => $diagPayload,
            ]);

        } catch (\Throwable $e) {
            log_error('[AIVO] user-data error', ['error' => $e->getMessage()]);
            json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    // ── POST /api/forgot-password ────────────────────────────────
    public function forgotPassword(): void
    {
        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['status' => 'ok']);
            return;
        }

        try {
            $user = User::where('email', $email)->first();

            if ($user) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $user->reset_token        = $token;
                $user->reset_token_expiry = $expiresAt;
                $user->save();

                $firstName   = explode(' ', trim($user->name ?: $email))[0];
                $frontendUrl = env('FRONTEND_URL', 'https://app.aivooptimize.com');
                $resetUrl    = $frontendUrl . '/#reset=' . $token;

                $this->sendResendEmail(
                    to:      $email,
                    subject: 'Reset your AIVO Optimize password',
                    html:    $this->resetEmailHtml($firstName, $resetUrl),
                    text:    "Hi {$firstName},\n\nReset your AIVO Optimize password here:\n{$resetUrl}\n\nThis link expires in 1 hour.\n\nIf you didn't request this, ignore this email.\n\n— The AIVO Team"
                );
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] forgot-password error', ['error' => $e->getMessage()]);
            json_response(['status' => 'ok']);
        }
    }


    // ── POST /api/reset-password ─────────────────────────────────
    public function resetPassword(): void
    {
        $body        = request_body();
        $token       = trim($body['token']        ?? '');
        $newPassword = trim($body['new_password'] ?? '');

        if (!$token) {
            http_response_code(400);
            json_response(['error' => 'Reset token is required.']);
            return;
        }

        if (strlen($newPassword) < 8) {
            http_response_code(400);
            json_response(['error' => 'Password must be at least 8 characters.']);
            return;
        }

        try {
            $user = User::where('reset_token', $token)->first();

            if (!$user) {
                http_response_code(400);
                json_response(['error' => 'Invalid or expired reset link. Please request a new one.']);
                return;
            }

            if (!$user->reset_token_expiry || strtotime($user->reset_token_expiry) < time()) {
                http_response_code(400);
                json_response(['error' => 'This reset link has expired. Please request a new one.']);
                return;
            }

            $user->password_hash      = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $user->reset_token        = null;
            $user->reset_token_expiry = null;
            $user->save();

            $sessionToken = $this->createSession($user);

            json_response([
                'success' => true,
                'token'   => $sessionToken,
                'email'   => $user->email,
            ]);

        } catch (\Throwable $e) {
            log_error('[AIVO] reset-password error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }


    // ── POST /api/cancel-subscription ───────────────────────────
    public function cancelSubscription(): void
    {
        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['status' => 'ok']);
            return;
        }

        try {
            $user = User::where('email', $email)->first();

            if ($user && $user->stripe_subscription_id) {
                $this->stripeRequest(
                    method: 'POST',
                    path:   '/v1/subscriptions/' . $user->stripe_subscription_id,
                    params: ['cancel_at_period_end' => 'true']
                );
            }

            if ($user) {
                $user->plan = 'cancelling';
                $user->save();
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] cancel-subscription error', ['error' => $e->getMessage()]);
            json_response(['status' => 'ok']);
        }
    }


    // ── POST /api/delete-account ─────────────────────────────────
    public function deleteAccount(): void
    {
        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['status' => 'ok']);
            return;
        }

        try {
            $user = User::where('email', $email)->first();

            if ($user) {
                if ($user->stripe_subscription_id) {
                    $this->stripeRequest(
                        method: 'DELETE',
                        path:   '/v1/subscriptions/' . $user->stripe_subscription_id,
                        params: []
                    );
                }

                DiagnosticRun::where('user_id', $user->id)->delete();
                $user->delete();
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] delete-account error', ['error' => $e->getMessage()]);
            json_response(['status' => 'ok']);
        }
    }


    // ── POST /api/probe-event ────────────────────────────────────
    public function probeEvent(): void
    {
        $body = request_body();

        $brand    = isset($body['brand'])    ? trim($body['brand'])    : '';
        $category = isset($body['category']) ? trim($body['category']) : '';
        $status   = isset($body['status'])   ? trim($body['status'])   : 'started';
        $email    = isset($body['email'])    ? strtolower(trim($body['email'])) : null;
        $dsov     = isset($body['dsov'])     ? (int)$body['dsov']      : null;
        $source   = isset($body['source'])   ? trim($body['source'])   : 'beta';

        if (!$brand || !$category) {
            json_response(['status' => 'ok']);
            return;
        }

        try {
            \Illuminate\Database\Capsule\Manager::table('probe_events')->insert([
                'brand'      => $brand,
                'product'    => isset($body['product']) ? trim($body['product']) : null,
                'category'   => $category,
                'status'     => $status,
                'email'      => $email,
                'dsov'       => $dsov,
                'source'     => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            log_error('[AIVO] probe-event error', ['error' => $e->getMessage()]);
        }

        json_response(['status' => 'ok']);
    }


    // ── GET /api/probe-stats ─────────────────────────────────────
    public function probeStats(): void
    {
        $password = $_GET['pw'] ?? ($_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '');
        if ($password !== env('ADMIN_PASSWORD', 'aivo-admin-2026')) {
            abort(401, 'Unauthorised');
        }

        try {
            $db = \Illuminate\Database\Capsule\Manager::table('probe_events');

            $started   = (clone $db)->where('status', 'started')->count();
            $completed = (clone $db)->where('status', 'completed')->count();
            $today     = (clone $db)->where('status', 'started')
                ->whereDate('created_at', date('Y-m-d'))
                ->count();
            $avgDsov   = (clone $db)->where('status', 'completed')
                ->whereNotNull('dsov')
                ->avg('dsov');

            $categories = (clone $db)
                ->selectRaw('category,
                    COUNT(CASE WHEN status = ? THEN 1 END) as started,
                    COUNT(CASE WHEN status = ? THEN 1 END) as completed',
                    ['started', 'completed'])
                ->groupBy('category')
                ->orderByRaw('COUNT(*) DESC')
                ->get()
                ->map(fn($r) => [
                    'category'  => $r->category,
                    'started'   => (int)$r->started,
                    'completed' => (int)$r->completed,
                ]);

            $topBrands = (clone $db)
                ->selectRaw('brand, COUNT(*) as count, AVG(dsov) as avg_dsov')
                ->groupBy('brand')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'brand'    => $r->brand,
                    'count'    => (int)$r->count,
                    'avg_dsov' => $r->avg_dsov !== null ? (int)round($r->avg_dsov) : null,
                ]);

            $recent = (clone $db)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn($r) => [
                    'brand'      => $r->brand,
                    'category'   => $r->category,
                    'status'     => $r->status,
                    'dsov'       => $r->dsov !== null ? (int)$r->dsov : null,
                    'email'      => $r->email,
                    'created_at' => $r->created_at,
                ]);

            json_response([
                'totals' => [
                    'started'   => $started,
                    'completed' => $completed,
                    'today'     => $today,
                    'avg_dsov'  => $avgDsov !== null ? (int)round($avgDsov) : null,
                ],
                'categories' => $categories,
                'top_brands' => $topBrands,
                'recent'     => $recent,
            ]);

        } catch (\Throwable $e) {
            log_error('[AIVO] probe-stats error', ['error' => $e->getMessage()]);
            abort(500, 'Internal error');
        }
    }


    // ── Private helpers ──────────────────────────────────────────

    private function createSession(User $user): string
    {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

        $user->session_token   = $token;
        $user->session_expires = $expires;
        $user->save();

        return $token;
    }

    private function getUserByToken(string $token): ?User
    {
        if (!$token) return null;

        return User::where('session_token', $token)
            ->where('session_expires', '>', now())
            ->first();
    }

    private function safeUser(User $user): array
    {
        $masterEmails = ['paul@aivoedge.net', 'tim@aivoedge.net', 'paul@aivoevidentia.com'];

        return [
            'name'          => $user->name          ?? '',
            'email'         => $user->email         ?? '',
            'company'       => $user->company       ?? '',
            'plan'          => $user->plan          ?? 'free',
            'betaAccess'    => (bool)($user->beta_access ?? false),
            'superadmin'    => in_array(strtolower($user->email ?? ''), $masterEmails),
            'testsUsed'     => (int)($user->tests_used  ?? 0),
            'testsMonth'    => $user->tests_month   ?? '',
            'registeredAt'  => (string)($user->created_at  ?? ''),
            'lastLoginAt'   => (string)($user->last_login_at ?? ''),
            'probeBrand'    => $user->probe_brand    ?? '',
            'probeCategory' => $user->probe_category ?? '',
        ];
    }

    private function stripeRequest(string $method, string $path, array $params): array
    {
        $key = env('STRIPE_SECRET_KEY');
        $url = 'https://api.stripe.com' . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $key . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response ?: '{}', true) ?? [];
    }

    // ── Send email via Resend ────────────────────────────────────
    private function sendResendEmail(string $to, string $subject, string $html, string $text): void
    {
        $apiKey = env('RESEND_API_KEY');
        $from   = env('POSTMARK_FROM', 'edge@aivoedge.net'); // reuses existing variable

        if (!$apiKey) {
            log_error('[AIVO] sendResendEmail: RESEND_API_KEY not set');
            return;
        }

        $payload = json_encode([
            'from'    => 'AIVO Optimize <' . $from . '>',
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            log_error('[AIVO] Resend email failed', [
                'to'       => $to,
                'status'   => $httpCode,
                'response' => $response,
            ]);
        }
    }

    private function resetEmailHtml(string $firstName, string $resetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 20px">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;border:1px solid #e5e7eb">
        <tr><td style="background:#000000;padding:28px 40px;border-radius:8px 8px 0 0">
          <span style="font-size:18px;font-weight:700;color:#FC8337">AIVO</span>
          <span style="font-size:18px;font-weight:300;color:#ffffff"> Optimize</span>
        </td></tr>
        <tr><td style="padding:40px">
          <p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6">Hi {$firstName},</p>
          <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6">You requested a password reset for your AIVO Optimize account. Click below to reset it.</p>
          <table cellpadding="0" cellspacing="0" style="margin:0 0 24px">
            <tr><td style="background:#FC8337;border-radius:6px">
              <a href="{$resetUrl}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#000000;text-decoration:none">Reset password</a>
            </td></tr>
          </table>
          <p style="margin:0;font-size:13px;color:#6b7280">This link expires in 1 hour. If you didn't request this, you can safely ignore this email.</p>
        </td></tr>
        <tr><td style="padding:20px 40px;border-top:1px solid #f3f4f6">
          <p style="margin:0;font-size:12px;color:#9ca3af">AIVO Optimize &nbsp;·&nbsp; <a href="https://app.aivooptimize.com" style="color:#FC8337;text-decoration:none">app.aivooptimize.com</a></p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
