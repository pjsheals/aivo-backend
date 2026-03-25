<?php

declare(strict_types=1);

use Aivo\Controllers\CheckoutController;
use Aivo\Controllers\WebhookController;
use Aivo\Controllers\HealthController;

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';

// ── Route table ───────────────────────────────────────────────────
$routes = [
    'GET'  => [
        '/'                  => [HealthController::class, 'index'],
        '/api/health'        => [HealthController::class, 'index'],
    ],
    'POST' => [
        '/api/create-checkout-session' => [CheckoutController::class, 'createSession'],
        '/api/verify-session'          => [CheckoutController::class, 'verifySession'],
        '/api/webhook'                 => [WebhookController::class, 'handle'],
    ],
];

// ── Dispatch ──────────────────────────────────────────────────────
$handler = $routes[$method][$uri] ?? null;

if ($handler === null) {
    abort(404, 'Route not found: ' . $method . ' ' . $uri);
}

[$class, $action] = $handler;
(new $class)->$action();
