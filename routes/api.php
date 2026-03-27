<?php

declare(strict_types=1);

use Aivo\Controllers\CheckoutController;
use Aivo\Controllers\WebhookController;
use Aivo\Controllers\HealthController;
use Aivo\Controllers\OptimizeController;
use Aivo\Controllers\ProxyController;
use Aivo\Controllers\EmailController;

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';

// ── Route table ───────────────────────────────────────────────────
$routes = [
    'GET'  => [
        '/'                  => [HealthController::class, 'index'],
        '/api/health'        => [HealthController::class, 'index'],
        '/api/user-data'     => [OptimizeController::class, 'getUserData'],
        '/api/probe-stats'   => [OptimizeController::class, 'probeStats'],
    ],
    'POST' => [
        // Stripe
        '/api/create-checkout-session' => [CheckoutController::class, 'createSession'],
        '/api/verify-session'          => [CheckoutController::class, 'verifySession'],
        '/api/webhook'                 => [WebhookController::class, 'handle'],
        // User management
        '/api/register'                => [OptimizeController::class, 'register'],
        '/api/sync-diagnostic'         => [OptimizeController::class, 'syncDiagnostic'],
        '/api/forgot-password'         => [OptimizeController::class, 'forgotPassword'],
        '/api/cancel-subscription'     => [OptimizeController::class, 'cancelSubscription'],
        '/api/delete-account'          => [OptimizeController::class, 'deleteAccount'],
        '/api/probe-event'             => [OptimizeController::class, 'probeEvent'],
        // AI proxy — keys never leave the server
        '/api/proxy'                   => [ProxyController::class, 'handle'],
        '/api/send-email'              => [EmailController::class, 'handle'],
    ],
];

// ── Dispatch ──────────────────────────────────────────────────────
$handler = $routes[$method][$uri] ?? null;

if ($handler === null) {
    abort(404, 'Route not found: ' . $method . ' ' . $uri);
}

[$class, $action] = $handler;
(new $class)->$action();
