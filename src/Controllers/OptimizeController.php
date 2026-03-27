<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Models\User;
use Aivo\Models\DiagnosticRun;

class OptimizeController
{
    // ── POST /api/register ───────────────────────────────────────
    // Upserts user on registration. Safe to call multiple times.
    public function register(): void
    {
        $body = request_body();

        $email   = isset($body['email'])   ? strtolower(trim($body['email'])) : null;
        $name    = $body['name']            ?? '';
        $company = $body['company']         ?? '';
        $plan    = $body['plan']            ?? 'free';
        $beta    = (bool)($body['beta_access']    ?? false);
        $brand   = $body['probe_brand']     ?? '';
        $cat     = $body['probe_category']  ?? '';

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Valid email required');
        }

        try {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Update existing
                $user->name           = $name    ?: $user->name;
                $user->company        = $company ?: $user->company;
                $user->plan           = $plan;
                $user->beta_access    = $beta;
                $user->probe_brand    = $brand   ?: $user->probe_brand;
                $user->probe_category = $cat     ?: $user->probe_category;
                $user->save();
            } else {
                // Create new
                $user = User::create([
                    'email'          => $email,
                    'name'           => $name    ?: explode('@', $email)[0],
                    'company'        => $company,
                    'plan'           => $plan,
                    'beta_access'    => $beta,
                    'probe_brand'    => $brand,
                    'probe_category' => $cat,
                    'tests_used'     => 0,
                ]);
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] register error', ['error' => $e->getMessage()]);
            json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    // ── POST /api/sync-diagnostic ────────────────────────────────
    // Stores slim diagnostic results after a full run.
    // Keeps last 10 per user — oldest pruned automatically.
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
                // Register on the fly so diagnostic is never lost
                $user = User::create([
                    'email' => $email,
                    'name'  => explode('@', $email)[0],
                    'plan'  => 'free',
                ]);
            }

            // Save diagnostic run
            DiagnosticRun::create([
                'user_id'  => $user->id,
                'brand'    => $diag['brand'],
                'category' => $diag['category'] ?? '',
                'results'  => $diag['results'],
            ]);

            // Prune — keep only 10 most recent per user
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
    // Called on session restore. Returns plan + latest diagnostic.
    // HTML merges this over localStorage without blocking the UI.
    public function getUserData(): void
    {
        $email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(400, 'Valid email required');
        }

        try {
            $user = User::where('email', $email)->first();

            if (!$user) {
                // Not found — return null so HTML falls back to localStorage
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
    // Always returns 200 — never reveals whether email exists.
    // Generates a reset token and sends via Postmark.
    public function forgotPassword(): void
    {
        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Still 200 — don't leak anything
            json_response(['status' => 'ok']);
            return;
        }

        try {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Generate a secure token
                $token     = bin2hex(random_bytes(32)); // 64 char hex string
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                // Store token in user record (reuse a spare column or add one)
                // We store in a simple way — token + expiry as JSON in a nullable column
                // Uses the probe_brand/probe_category pattern as reference
                $user->reset_token        = $token;
                $user->reset_token_expiry = $expiresAt;
                $user->save();

                $firstName   = explode(' ', trim($user->name ?: $email))[0];
                $frontendUrl = env('FRONTEND_URL', 'https://aivoedge.net');
                $resetUrl    = $frontendUrl . '?reset=' . $token;

                // Send via Postmark
                $this->sendPostmarkEmail(
                    to:      $email,
                    subject: 'Reset your AIVO Optimize password',
                    html:    $this->resetEmailHtml($firstName, $resetUrl),
                    text:    "Hi {$firstName},\n\nReset your AIVO Optimize password here:\n{$resetUrl}\n\nThis link expires in 1 hour.\n\nIf you didn't request this, ignore this email.\n\n— The AIVO Team"
                );
            }

            // Always 200
            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] forgot-password error', ['error' => $e->getMessage()]);
            // Still 200 — fire and forget from the HTML
            json_response(['status' => 'ok']);
        }
    }


    // ── POST /api/cancel-subscription ───────────────────────────
    // Cancels Stripe subscription at period end.
    // User keeps access until billing cycle closes.
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
                // Cancel at period end via Stripe API
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
            json_response(['status' => 'ok']); // always 200
        }
    }


    // ── POST /api/delete-account ─────────────────────────────────
    // Cancels Stripe subscription immediately then deletes all
    // user data. HTML wipes localStorage and redirects instantly.
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
                // Cancel Stripe subscription immediately
                if ($user->stripe_subscription_id) {
                    $this->stripeRequest(
                        method: 'DELETE',
                        path:   '/v1/subscriptions/' . $user->stripe_subscription_id,
                        params: []
                    );
                }

                // Delete all diagnostic runs then the user
                DiagnosticRun::where('user_id', $user->id)->delete();
                $user->delete();
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[AIVO] delete-account error', ['error' => $e->getMessage()]);
            json_response(['status' => 'ok']); // always 200
        }
    }


    // ── Private helpers ──────────────────────────────────────────

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

    private function sendPostmarkEmail(string $to, string $subject, string $html, string $text): void
    {
        $token = env('POSTMARK_TOKEN');
        $from  = env('POSTMARK_FROM', 'edge@aivoedge.net');

        if (!$token) return; // Postmark not configured yet — skip silently

        $payload = json_encode([
            'From'          => $from,
            'To'            => $to,
            'Subject'       => $subject,
            'HtmlBody'      => $html,
            'TextBody'      => $text,
            'MessageStream' => 'outbound',
        ]);

        $ch = curl_init('https://api.postmarkapp.com/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $token,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
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
        <tr><td style="background:#0F1E35;padding:28px 40px;border-radius:8px 8px 0 0">
          <span style="font-size:18px;font-weight:700;color:#10b981">AIVO</span>
          <span style="font-size:18px;font-weight:300;color:#ffffff"> Optimize</span>
        </td></tr>
        <tr><td style="padding:40px">
          <p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6">Hi {$firstName},</p>
          <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6">You requested a password reset for your AIVO Optimize account. Click below to reset it.</p>
          <table cellpadding="0" cellspacing="0" style="margin:0 0 24px">
            <tr><td style="background:#10b981;border-radius:6px">
              <a href="{$resetUrl}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none">Reset password</a>
            </td></tr>
          </table>
          <p style="margin:0;font-size:13px;color:#6b7280">This link expires in 1 hour. If you didn't request this, you can safely ignore this email.</p>
        </td></tr>
        <tr><td style="padding:20px 40px;border-top:1px solid #f3f4f6">
          <p style="margin:0;font-size:12px;color:#9ca3af">AIVO Edge &nbsp;·&nbsp; <a href="https://aivoedge.net" style="color:#10b981;text-decoration:none">aivoedge.net</a></p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
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
        $password = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
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
    }}
