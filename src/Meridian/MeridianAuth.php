<?php

declare(strict_types=1);

namespace Aivo\Meridian;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * MeridianAuth — shared authentication helper for all Meridian controllers.
 *
 * Usage in a controller method:
 *   $auth = MeridianAuth::require();          // aborts 401 if not authenticated
 *   $auth = MeridianAuth::require('admin');   // aborts 403 if not admin role
 *   $auth->agency_id  — current agency ID
 *   $auth->user_id    — current user ID
 *   $auth->role       — 'admin' | 'analyst' | 'viewer'
 *   $auth->is_superadmin — bool
 */
class MeridianAuth
{
    public int    $agency_id;
    public int    $user_id;
    public string $role;
    public bool   $is_superadmin;
    public object $user;
    public object $agency;

    private function __construct() {}

    // ── Authenticate and return auth context ─────────────────────
    // Aborts with 401 if no valid session token.
    // Aborts with 403 if role requirement not met.
    public static function require(string $minRole = 'viewer'): self
    {
        $token = self::extractToken();

        if (!$token) {
            http_response_code(401);
            json_response(['error' => 'Authentication required.']);
            exit;
        }

        $session = DB::table('meridian_user_sessions')
            ->where('session_token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            http_response_code(401);
            json_response(['error' => 'Session expired. Please sign in again.']);
            exit;
        }

        $user = DB::table('meridian_agency_users')
            ->where('id', $session->user_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (!$user) {
            http_response_code(401);
            json_response(['error' => 'Account not found or deactivated.']);
            exit;
        }

        $agency = DB::table('meridian_agencies')
            ->where('id', $session->agency_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$agency || $agency->plan_status === 'suspended') {
            http_response_code(403);
            json_response(['error' => 'Agency account suspended.']);
            exit;
        }

        // Role hierarchy: admin > analyst > viewer
        $roleHierarchy = ['viewer' => 1, 'analyst' => 2, 'admin' => 3];
        $userLevel     = $roleHierarchy[$user->role]     ?? 0;
        $requiredLevel = $roleHierarchy[$minRole]        ?? 0;

        $superadminEmails = ['paul@aivoedge.net', 'tim@aivoedge.net', 'paul@aivoevidentia.com'];
        $isSuperadmin     = in_array(strtolower($user->email), $superadminEmails, true);

        // Superadmins bypass role checks
        if (!$isSuperadmin && $userLevel < $requiredLevel) {
            http_response_code(403);
            json_response(['error' => 'Insufficient permissions.']);
            exit;
        }

        // Update last active on session
        DB::table('meridian_user_sessions')
            ->where('session_token', $token)
            ->update(['last_active_at' => now()]);

        $auth                = new self();
        $auth->agency_id     = (int)$session->agency_id;
        $auth->user_id       = (int)$user->id;
        $auth->role          = $user->role;
        $auth->is_superadmin = $isSuperadmin;
        $auth->user          = $user;
        $auth->agency        = $agency;

        return $auth;
    }

    // ── Create a new session token ───────────────────────────────
    public static function createSession(int $userId, int $agencyId): string
    {
        $token = bin2hex(random_bytes(32));

        DB::table('meridian_user_sessions')->insert([
            'user_id'       => $userId,
            'agency_id'     => $agencyId,
            'session_token' => $token,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'last_active_at'=> now(),
            'expires_at'    => date('Y-m-d H:i:s', strtotime('+30 days')),
            'created_at'    => now(),
        ]);

        return $token;
    }

    // ── Invalidate a session token ───────────────────────────────
    public static function revokeSession(string $token): void
    {
        DB::table('meridian_user_sessions')
            ->where('session_token', $token)
            ->delete();
    }

    // ── Format user for API response ─────────────────────────────
    public static function safeUser(object $user, object $agency): array
    {
        $superadminEmails = ['paul@aivoedge.net', 'tim@aivoedge.net', 'paul@aivoevidentia.com'];

        return [
            'id'           => (int)$user->id,
            'email'        => $user->email,
            'firstName'    => $user->first_name ?? '',
            'lastName'     => $user->last_name  ?? '',
            'role'         => $user->role,
            'isSuperadmin' => in_array(strtolower($user->email), $superadminEmails, true),
            'agencyId'     => (int)$agency->id,
            'agencyName'   => $agency->name,
            'agencySlug'   => $agency->slug,
            'planType'     => $agency->plan_type,
            'planStatus'   => $agency->plan_status,
            'lastLoginAt'  => (string)($user->last_login_at ?? ''),
        ];
    }

    // ── Extract Bearer token from Authorization header ───────────
    private static function extractToken(): string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            return trim(substr($authHeader, 7));
        }
        return '';
    }
}
