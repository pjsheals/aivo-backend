<?php

declare(strict_types=1);

use Aivo\Controllers\CheckoutController;
use Aivo\Controllers\WebhookController;
use Aivo\Controllers\HealthController;
use Aivo\Controllers\OptimizeController;
use Aivo\Controllers\ProxyController;
use Aivo\Controllers\EmailController;
use Aivo\Controllers\ProbeDataController;
use Aivo\Controllers\AdminController;

// ── Meridian controllers ───────────────────────────────────────────
use Aivo\Controllers\MeridianAuthController;
use Aivo\Controllers\MeridianAgencyController;
use Aivo\Controllers\MeridianClientController;
use Aivo\Controllers\MeridianBrandController;
use Aivo\Controllers\MeridianDashboardController;
use Aivo\Controllers\MeridianAuditController;
use Aivo\Controllers\MeridianSuperadminController;
use Aivo\Controllers\MeridianRemediationController;
use Aivo\Controllers\MeridianPdfController;
use Aivo\Controllers\MeridianClassifierController;
use Aivo\Controllers\MeridianBrandContextController;
use Aivo\Controllers\MeridianEvidenceController;
use Aivo\Controllers\MeridianAtomController;
use Aivo\Controllers\MeridianPublicationController;
use Aivo\Controllers\MeridianCrawlerController;
use Aivo\Controllers\MeridianAttributionController;

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';

// ── Route table ───────────────────────────────────────────────────
$routes = [
    'GET'  => [
        // ── Existing AIVO Optimize routes (unchanged) ──────────
        '/'                       => [HealthController::class,    'index'],
        '/api/health'             => [HealthController::class,    'index'],
        '/probe-intelligence'     => [HealthController::class,    'dashboard'],
        '/api/user-data'          => [OptimizeController::class,  'getUserData'],
        '/api/probe-stats'        => [OptimizeController::class,  'probeStats'],
        '/api/probe-data/stats'   => [ProbeDataController::class, 'stats'],
        '/api/admin/users'        => [AdminController::class,     'getUsers'],
        '/api/admin/stats'        => [AdminController::class,     'getStats'],

        // ── Meridian: Auth ─────────────────────────────────────
        '/api/meridian/auth/me'             => [MeridianAuthController::class,      'me'],

        // ── Meridian: Dashboard ────────────────────────────────
        '/api/meridian/dashboard'           => [MeridianDashboardController::class, 'overview'],
        '/api/meridian/dashboard/brands'    => [MeridianDashboardController::class, 'brands'],

        // ── Meridian: Clients ──────────────────────────────────
        '/api/meridian/clients'             => [MeridianClientController::class,    'list'],

        // ── Meridian: Brands ───────────────────────────────────
        '/api/meridian/brands'              => [MeridianBrandController::class,     'list'],
        '/api/meridian/brand'               => [MeridianBrandController::class,     'detail'],

        // ── Meridian: Audits ───────────────────────────────────
        '/api/meridian/audit/status'        => [MeridianAuditController::class,     'status'],
        '/api/meridian/audit/history'       => [MeridianAuditController::class,     'history'],

        // ── Meridian: Remediation ──────────────────────────────
        '/api/meridian/remediation'         => [MeridianRemediationController::class, 'fetch'],

        // ── Meridian: Superadmin ───────────────────────────────
        '/api/meridian/admin/agencies'      => [MeridianSuperadminController::class, 'agencies'],
        '/api/meridian/admin/usage'         => [MeridianSuperadminController::class, 'usage'],
        '/api/meridian/admin/audits'        => [MeridianSuperadminController::class, 'audits'],
        '/api/meridian/admin/corpus'        => [MeridianSuperadminController::class, 'corpus'],
        '/api/meridian/admin/methodology'   => [MeridianSuperadminController::class, 'methodology'],
        '/api/meridian/admin/model-watch'   => [MeridianSuperadminController::class, 'modelWatch'],

        // ── Meridian: PDF exports ──────────────────────────────
        '/api/meridian/pdf/remediation'     => [MeridianPdfController::class, 'remediationReport'],

        // ── Meridian: Filter Classifier ────────────────────────
        '/api/meridian/classify'            => [MeridianClassifierController::class, 'getClassifications'],

        // ── Meridian: Brand Context ────────────────────────────
        '/api/meridian/brand-context'       => [MeridianBrandContextController::class, 'list'],
        '/api/meridian/brand-context/download' => [MeridianBrandContextController::class, 'download'],

        // ── Meridian: Evidence Portal ──────────────────────────
        '/api/meridian/evidence'               => [MeridianEvidenceController::class, 'list'],
        '/api/meridian/evidence/gaps'          => [MeridianEvidenceController::class, 'gaps'],

        // ── Meridian: Atoms ────────────────────────────────────
        '/api/meridian/atoms'                  => [MeridianAtomController::class, 'list'],
        '/api/meridian/atoms/detail'           => [MeridianAtomController::class, 'detail'],

        // ── Meridian: Publication Pipeline ────────────────────
        '/api/meridian/publish/status'         => [MeridianPublicationController::class, 'status'],

        // ── Meridian: Crawler Instructions ────────────────────
        '/api/meridian/crawler'                => [MeridianCrawlerController::class, 'get'],

        // ── Meridian: Attribution ──────────────────────────────
        '/api/meridian/attribution/stats'      => [MeridianAttributionController::class, 'stats'],
        '/r'                                   => [MeridianAttributionController::class, 'redirect'],
    ],

    'POST' => [
        // ── Existing AIVO Optimize routes (unchanged) ──────────
        '/api/create-checkout-session' => [CheckoutController::class,   'createSession'],
        '/api/verify-session'          => [CheckoutController::class,   'verifySession'],
        '/api/webhook'                 => [WebhookController::class,    'handle'],
        '/api/register'                => [OptimizeController::class,   'register'],
        '/api/login'                   => [OptimizeController::class,   'login'],
        '/api/change-password'         => [OptimizeController::class,   'changePassword'],
        '/api/sync-diagnostic'         => [OptimizeController::class,   'syncDiagnostic'],
        '/api/forgot-password'         => [OptimizeController::class,   'forgotPassword'],
        '/api/reset-password'          => [OptimizeController::class,   'resetPassword'],
        '/api/cancel-subscription'     => [OptimizeController::class,   'cancelSubscription'],
        '/api/delete-account'          => [OptimizeController::class,   'deleteAccount'],
        '/api/probe-event'             => [OptimizeController::class,   'probeEvent'],
        '/api/proxy'                   => [ProxyController::class,      'handle'],
        '/api/send-email'              => [EmailController::class,      'handle'],
        '/api/probe-data'              => [ProbeDataController::class,  'store'],
        '/api/probe-data/stats'        => [ProbeDataController::class,  'stats'],
        '/api/admin/set-plan'          => [AdminController::class,      'setPlan'],
        '/api/admin/delete-user'       => [AdminController::class,      'deleteUser'],

        // ── Meridian: Auth ─────────────────────────────────────
        '/api/meridian/auth/register'       => [MeridianAuthController::class,      'register'],
        '/api/meridian/auth/login'          => [MeridianAuthController::class,      'login'],
        '/api/meridian/auth/logout'         => [MeridianAuthController::class,      'logout'],
        '/api/meridian/auth/forgot'         => [MeridianAuthController::class,      'forgot'],
        '/api/meridian/auth/reset'          => [MeridianAuthController::class,      'reset'],

        // ── Meridian: Agency ───────────────────────────────────
        '/api/meridian/agency/logo'         => [MeridianAgencyController::class,    'updateLogo'],

        // ── Meridian: Clients ──────────────────────────────────
        '/api/meridian/clients/create'      => [MeridianClientController::class,    'create'],
        '/api/meridian/clients/update'      => [MeridianClientController::class,    'update'],
        '/api/meridian/clients/delete'      => [MeridianClientController::class,    'delete'],

        // ── Meridian: Brands ───────────────────────────────────
        '/api/meridian/brands/create'       => [MeridianBrandController::class,     'create'],
        '/api/meridian/brands/update'       => [MeridianBrandController::class,     'update'],
        '/api/meridian/brands/delete'       => [MeridianBrandController::class,     'delete'],
        '/api/meridian/brands/prompts'      => [MeridianBrandController::class,     'savePromptsEndpoint'],

        // ── Meridian: Audits ───────────────────────────────────
        '/api/meridian/audits/initiate'     => [MeridianAuditController::class,     'initiate'],
        '/api/meridian/audits/complete'     => [MeridianAuditController::class,     'complete'],

        // ── Meridian: Remediation ──────────────────────────────
        '/api/meridian/remediation/generate' => [MeridianRemediationController::class, 'generate'],

        // ── Meridian: Superadmin ───────────────────────────────
        '/api/meridian/admin/login'                  => [MeridianSuperadminController::class, 'login'],
        '/api/meridian/admin/logout'                 => [MeridianSuperadminController::class, 'logout'],
        '/api/meridian/admin/agencies/create'        => [MeridianSuperadminController::class, 'createAgency'],
        '/api/meridian/admin/agencies/set-plan'      => [MeridianSuperadminController::class, 'setPlan'],
        '/api/meridian/admin/agencies/suspend'       => [MeridianSuperadminController::class, 'suspend'],
        '/api/meridian/admin/agencies/unsuspend'     => [MeridianSuperadminController::class, 'unsuspend'],
        '/api/meridian/admin/audits/rerun'           => [MeridianSuperadminController::class, 'rerun'],
        '/api/meridian/admin/methodology/publish'    => [MeridianSuperadminController::class, 'publishMethodology'],
        '/api/meridian/admin/alerts/acknowledge'     => [MeridianSuperadminController::class, 'acknowledgeAlert'],
        '/api/meridian/admin/model-watch/run'        => [MeridianSuperadminController::class, 'runModelWatch'],

        // ── Meridian: Filter Classifier ────────────────────────
        '/api/meridian/classify'            => [MeridianClassifierController::class, 'classify'],
        '/api/meridian/classify/all'        => [MeridianClassifierController::class, 'classifyAll'],

        // ── Meridian: Brand Context ────────────────────────────
        '/api/meridian/brand-context/generate' => [MeridianBrandContextController::class, 'generate'],

        // ── Meridian: Evidence Portal ──────────────────────────
        '/api/meridian/evidence/submit'        => [MeridianEvidenceController::class, 'submit'],
        '/api/meridian/evidence/verify'        => [MeridianEvidenceController::class, 'verify'],
        '/api/meridian/evidence/delete'        => [MeridianEvidenceController::class, 'delete'],

        // ── Meridian: Atoms ────────────────────────────────────
        '/api/meridian/atoms/generate'         => [MeridianAtomController::class, 'generate'],
        '/api/meridian/atoms/generate-all'     => [MeridianAtomController::class, 'generateAll'],
        '/api/meridian/atoms/mark-published'   => [MeridianAtomController::class, 'markPublished'],

        // ── Meridian: Publication Pipeline ────────────────────
        '/api/meridian/publish/queue'          => [MeridianPublicationController::class, 'queue'],
        '/api/meridian/publish/process'        => [MeridianPublicationController::class, 'process'],
        '/api/meridian/publish/manual-submitted' => [MeridianPublicationController::class, 'markManualSubmitted'],

        // ── Meridian: Crawler Instructions ────────────────────
        '/api/meridian/crawler/generate'       => [MeridianCrawlerController::class, 'generate'],

        // ── Meridian: Attribution ──────────────────────────────
        '/api/meridian/attribution/link'       => [MeridianAttributionController::class, 'createLink'],
    ],
];

// ── Dispatch ──────────────────────────────────────────────────────
$handler = $routes[$method][$uri] ?? null;

// ── Prefix match for /r/{token} redirect ──────────────────────────
if ($handler === null && $method === 'GET' && str_starts_with($uri, '/r/')) {
    $handler = [MeridianAttributionController::class, 'redirect'];
}

if ($handler === null) {
    abort(404, 'Route not found: ' . $method . ' ' . $uri);
}

[$class, $action] = $handler;
(new $class)->$action();
