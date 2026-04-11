<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Meridian\MeridianAuth;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianAuthController
 *
 * POST /api/meridian/auth/register   — Register new agency + first admin user
 * POST /api/meridian/auth/login      — Login
 * POST /api/meridian/auth/logout     — Logout (revoke session)
 * GET  /api/meridian/auth/me         — Get current user + agency context
 * POST /api/meridian/auth/forgot     — Send password reset email
 * POST /api/meridian/auth/reset      — Reset password with token
 */
class MeridianAuthController
{
    // ── POST /api/meridian/auth/register ─────────────────────────
    // Creates a new agency and its first admin user.
    // Called during agency onboarding flow.
    public function register(): void
    {
        $body = request_body();

        $agencyName = trim($body['agency_name'] ?? '');
        $email      = isset($body['email']) ? strtolower(trim($body['email'])) : null;
        $firstName  = trim($body['first_name'] ?? '');
        $lastName   = trim($body['last_name']  ?? '');
        $password   = trim($body['password']   ?? '');

        if (!$agencyName) {
            http_response_code(422);
            json_response(['error' => 'Agency name is required.']);
            return;
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            json_response(['error' => 'Valid email address is required.']);
            return;
        }
        if (strlen($password) < 8) {
            http_response_code(422);
            json_response(['error' => 'Password must be at least 8 characters.']);
            return;
        }

        // Check email not already in use
        $existing = DB::table('meridian_agency_users')
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            http_response_code(409);
            json_response(['error' => 'An account with this email already exists.']);
            return;
        }

        try {
            DB::beginTransaction();

            // Generate unique slug from agency name
            $slug = $this->generateSlug($agencyName);

            // Create agency
            $agencyId = DB::table('meridian_agencies')->insertGetId([
                'deployment_id'  => 1,
                'name'           => $agencyName,
                'slug'           => $slug,
                'contact_email'  => $email,
                'billing_email'  => $email,
                'plan_type'      => 'fixed_starter',
                'plan_status'    => 'trial',
                'trial_ends_at'  => date('Y-m-d H:i:s', strtotime('+14 days')),
                'max_clients'    => 3,
                'max_brands'     => 15,
                'max_users'      => 2,
                'monthly_audit_allowance' => 6,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Create white-label config with defaults
            DB::table('meridian_white_label_configs')->insert([
                'agency_id'           => $agencyId,
                'agency_display_name' => $agencyName,
                'show_aivo_branding'  => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            // Create first admin user
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $userId = DB::table('meridian_agency_users')->insertGetId([
                'agency_id'     => $agencyId,
                'email'         => $email,
                'password_hash' => $passwordHash,
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'role'          => 'admin',
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // Log action
            DB::table('meridian_audit_log')->insert([
                'agency_id'   => $agencyId,
                'user_id'     => $userId,
                'action'      => 'agency.created',
                'entity_type' => 'agency',
                'entity_id'   => $agencyId,
                'metadata'    => json_encode(['agency_name' => $agencyName, 'email' => $email]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => now(),
            ]);

            DB::commit();

            // Create session
            $token = MeridianAuth::createSession($userId, $agencyId);

            $user   = DB::table('meridian_agency_users')->find($userId);
            $agency = DB::table('meridian_agencies')->find($agencyId);

            json_response([
                'status' => 'ok',
                'token'  => $token,
                'user'   => MeridianAuth::safeUser($user, $agency),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            log_error('[Meridian] register error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }

    // ── POST /api/meridian/auth/login ────────────────────────────
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

        try {
            $user = DB::table('meridian_agency_users')
                ->where('email', $email)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            // Same error for wrong email or wrong password — prevents enumeration
            if (!$user || empty($user->password_hash) ||
                !password_verify($password, $user->password_hash)) {
                http_response_code(401);
                json_response(['error' => 'Incorrect email or password.']);
                return;
            }

            $agency = DB::table('meridian_agencies')
                ->where('id', $user->agency_id)
                ->whereNull('deleted_at')
                ->first();

            if (!$agency || $agency->plan_status === 'suspended') {
                http_response_code(403);
                json_response(['error' => 'Agency account is suspended.']);
                return;
            }

            // Update last login
            DB::table('meridian_agency_users')
                ->where('id', $user->id)
                ->update(['last_login_at' => now(), 'updated_at' => now()]);

            $token = MeridianAuth::createSession((int)$user->id, (int)$user->agency_id);

            json_response([
                'status' => 'ok',
                'token'  => $token,
                'user'   => MeridianAuth::safeUser($user, $agency),
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian] login error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }

    // ── POST /api/meridian/auth/logout ───────────────────────────
    public function logout(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token      = str_starts_with($authHeader, 'Bearer ')
            ? trim(substr($authHeader, 7)) : '';

        if ($token) {
            MeridianAuth::revokeSession($token);
        }

        json_response(['status' => 'ok']);
    }

    // ── GET /api/meridian/auth/me ────────────────────────────────
    public function me(): void
    {
        $auth = MeridianAuth::require();

        // Get white-label config
        $wl = DB::table('meridian_white_label_configs')
            ->where('agency_id', $auth->agency_id)
            ->first();

        // Get plan limits
        $plan = DB::table('meridian_pricing_plans')
            ->where('plan_type', $auth->agency->plan_type)
            ->first();

        json_response([
            'status' => 'ok',
            'user'   => MeridianAuth::safeUser($auth->user, $auth->agency),
            'agency' => [
                'id'             => (int)$auth->agency->id,
                'name'           => $auth->agency->name,
                'slug'           => $auth->agency->slug,
                'planType'       => $auth->agency->plan_type,
                'planStatus'     => $auth->agency->plan_status,
                'trialEndsAt'    => $auth->agency->trial_ends_at,
                'maxClients'     => $auth->agency->max_clients,
                'maxBrands'      => $auth->agency->max_brands,
                'maxUsers'       => $auth->agency->max_users,
                'monthlyAuditAllowance' => $auth->agency->monthly_audit_allowance,
            ],
            'whiteLabelConfig' => $wl ? [
                'displayName'     => $wl->agency_display_name,
                'logoUrl'         => $wl->logo_url,
                'primaryColour'   => $wl->primary_colour,
                'secondaryColour' => $wl->secondary_colour,
                'showAivoBranding'=> (bool)$wl->show_aivo_branding,
                'customDomain'    => $wl->custom_domain,
            ] : null,
            'features' => $plan ? [
                'whiteLabelEnabled'     => (bool)$plan->white_label_enabled,
                'competitiveIntel'      => (bool)$plan->competitive_intel_enabled,
                'adVerification'        => (bool)$plan->ad_verification_enabled,
                'apiAccess'             => (bool)$plan->api_access_enabled,
                'autoScheduling'        => (bool)$plan->auto_scheduling_enabled,
                'benchmarkAccess'       => (bool)$plan->benchmark_access_enabled,
                'remediationPlanner'    => (bool)$plan->remediation_planner_enabled,
            ] : null,
        ]);
    }

    // ── POST /api/meridian/auth/forgot ───────────────────────────
    public function forgot(): void
    {
        $body  = request_body();
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : null;

        // Always return ok — don't reveal whether email exists
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['status' => 'ok']);
            return;
        }

        try {
            $user = DB::table('meridian_agency_users')
                ->where('email', $email)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if ($user) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                DB::table('meridian_agency_users')
                    ->where('id', $user->id)
                    ->update([
                        'reset_token'   => $token,
                        'reset_expires' => $expiresAt,
                        'updated_at'    => now(),
                    ]);

                $firstName = $user->first_name ?: explode('@', $email)[0];
                $resetUrl  = 'https://meridian.aivoedge.net/reset.html#reset=' . $token;

                $this->sendResetEmail($email, $firstName, $resetUrl);
            }

            json_response(['status' => 'ok']);

        } catch (\Throwable $e) {
            log_error('[Meridian] forgot-password error', ['error' => $e->getMessage()]);
            json_response(['status' => 'ok']);
        }
    }

    // ── POST /api/meridian/auth/reset ────────────────────────────
    public function reset(): void
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
            $user = DB::table('meridian_agency_users')
                ->where('reset_token', $token)
                ->whereNull('deleted_at')
                ->first();

            if (!$user) {
                http_response_code(400);
                json_response(['error' => 'Invalid or expired reset link. Please request a new one.']);
                return;
            }

            if (!$user->reset_expires || strtotime($user->reset_expires) < time()) {
                http_response_code(400);
                json_response(['error' => 'This reset link has expired. Please request a new one.']);
                return;
            }

            DB::table('meridian_agency_users')
                ->where('id', $user->id)
                ->update([
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                    'reset_token'   => null,
                    'reset_expires' => null,
                    'updated_at'    => now(),
                ]);

            $agency = DB::table('meridian_agencies')->find($user->agency_id);
            $token  = MeridianAuth::createSession((int)$user->id, (int)$user->agency_id);

            $updatedUser = DB::table('meridian_agency_users')->find($user->id);

            json_response([
                'status' => 'ok',
                'token'  => $token,
                'user'   => MeridianAuth::safeUser($updatedUser, $agency),
            ]);

        } catch (\Throwable $e) {
            log_error('[Meridian] reset-password error', ['error' => $e->getMessage()]);
            http_response_code(500);
            json_response(['error' => 'Server error. Please try again.']);
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');
        $base = $slug;
        $i    = 1;

        while (DB::table('meridian_agencies')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function sendResetEmail(string $to, string $firstName, string $resetUrl): void
    {
        $apiKey  = env('RESEND_API_KEY');
        $payload = json_encode([
            'from'    => 'AIVO Meridian <meridian@aivoedge.net>',
            'to'      => [$to],
            'subject' => 'Reset your AIVO Meridian password',
            'html'    => $this->resetEmailHtml($firstName, $resetUrl),
            'text'    => "Hi {$firstName},\n\nReset your AIVO Meridian password:\n{$resetUrl}\n\nExpires in 1 hour.\n\n— AIVO Meridian",
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
        curl_exec($ch);
        curl_close($ch);
    }

    private function resetEmailHtml(string $firstName, string $resetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#EAE0CF;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 20px">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#FBF8F3;border-radius:8px;border:1px solid #d6e4ea">
        <tr><td style="background:#213448;padding:28px 40px;border-radius:8px 8px 0 0">
          <span style="font-size:18px;font-weight:600;color:#fff">AIVO Meridian</span>
        </td></tr>
        <tr><td style="padding:40px">
          <p style="margin:0 0 16px;font-size:15px;color:#1a2a38;line-height:1.6">Hi {$firstName},</p>
          <p style="margin:0 0 24px;font-size:15px;color:#3d5a6e;line-height:1.6">You requested a password reset for your AIVO Meridian account. Click below to set a new password.</p>
          <table cellpadding="0" cellspacing="0" style="margin:0 0 24px">
            <tr><td style="background:#213448;border-radius:6px">
              <a href="{$resetUrl}" style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#94B4C1;text-decoration:none">Reset password →</a>
            </td></tr>
          </table>
          <p style="margin:0;font-size:13px;color:#6b8fa0">This link expires in 1 hour. If you didn't request this, you can safely ignore this email.</p>
        </td></tr>
        <tr><td style="padding:20px 40px;border-top:1px solid #d6e4ea">
          <p style="margin:0;font-size:12px;color:#94B4C1">AIVO Meridian &nbsp;·&nbsp; Agency Intelligence Platform</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
