<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('START_TIME', microtime(true));

// ── Autoloader ──────────────────────────────────────────────────
require BASE_PATH . '/vendor/autoload.php';

// ── Environment ─────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
try {
    $dotenv->load();
} catch (\Throwable $e) {
    // .env not required in production if env vars are set directly
}

// ── Bootstrap ────────────────────────────────────────────────────
require BASE_PATH . '/src/bootstrap.php';

// ── CORS ─────────────────────────────────────────────────────────
$allowedOrigins = array_map('trim', explode(',', env('ALLOWED_ORIGINS', '*')));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $allowedOrigins)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin === 'null' && in_array('null', $allowedOrigins)) {
    header('Access-Control-Allow-Origin: null');
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: ' . (env('APP_ENV') === 'production' ? '' : '*'));
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Password');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Router ───────────────────────────────────────────────────────
require BASE_PATH . '/routes/api.php';