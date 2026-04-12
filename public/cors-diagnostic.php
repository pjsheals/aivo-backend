<?php
/**
 * AIVO — CORS Diagnostic
 * Shows exactly what PHP sees for origin and ALLOWED_ORIGINS.
 * DELETE AFTER USE.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$secret   = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
$expected = getenv('MERIDIAN_INTERNAL_SECRET') ?: '';
if (!$secret || $secret !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$rawOrigins     = getenv('ALLOWED_ORIGINS') ?: '(not set)';
$allowedOrigins = array_filter(array_map('trim', explode(',', $rawOrigins)));
$origin         = $_SERVER['HTTP_ORIGIN'] ?? '(none)';

$inList = in_array($origin, $allowedOrigins);

echo json_encode([
    'origin_received'       => $origin,
    'allowed_origins_raw'   => $rawOrigins,
    'allowed_origins_parsed'=> array_values($allowedOrigins),
    'origin_in_list'        => $inList,
    'origin_length'         => strlen($origin),
    'first_origin_length'   => strlen(array_values($allowedOrigins)[0] ?? ''),
    'first_origin_hex'      => bin2hex(array_values($allowedOrigins)[0] ?? ''),
    'request_method'        => $_SERVER['REQUEST_METHOD'],
], JSON_PRETTY_PRINT);
