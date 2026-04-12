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
// When credentials are enabled, Access-Control-Allow-Origin must be
// the exact origin — not '*'. So we always echo back the requesting
// origin if it is in the allowlist, or if ALLOWED_ORIGINS = *.
$rawOrigins     = env('ALLOWED_ORIGINS', '*');
$allowedOrigins = array_filter(array_map('trim', explode(',', $rawOrigins)));
$origin         = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowAll = in_array('*', $allowedOrigins);

if (!$origin) {
    // No origin header — server-to-server or direct request (e.g. healthcheck)
    // CORS does not apply — allow through
    header('Access-Control-Allow-Origin: *');
} elseif ($allowAll || in_array($origin, $allowedOrigins)) {
    // Known origin — echo back exact origin (required when credentials: true)
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    // Unknown origin — block
    header('Access-Control-Allow-Origin: null');
    http_response_code(403);
    echo json_encode(['error' => 'Origin not allowed']);
    exit;
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Password, X-Meridian-Secret');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
// ── Router ───────────────────────────────────────────────────────
require BASE_PATH . '/routes/api.php';
