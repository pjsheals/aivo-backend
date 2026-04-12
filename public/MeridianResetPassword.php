<?php
/**
 * AIVO Meridian — Reset Admin Password
 * Resets paul@aivoedge.net and tim@aivoedge.net to the specified password.
 * DELETE IMMEDIATELY AFTER USE.
 */

header('Content-Type: application/json');

$secret   = $_SERVER['HTTP_X_MERIDIAN_SECRET'] ?? '';
$expected = getenv('MERIDIAN_INTERNAL_SECRET') ?: '';
if (!$secret || $secret !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$dbUrl = getenv('DATABASE_URL');
try {
    $parts  = parse_url($dbUrl);
    $dsn    = "pgsql:host={$parts['host']};port={$parts['port']};dbname=" . ltrim($parts['path'], '/') . ";sslmode=require";
    $pdo    = new PDO($dsn, $parts['user'], $parts['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$newHash = password_hash('L3adbank1@64', PASSWORD_BCRYPT, ['cost' => 12]);

$results = [];
foreach (['paul@aivoedge.net', 'tim@aivoedge.net'] as $email) {
    $stmt = $pdo->prepare("
        UPDATE meridian_agency_users
        SET password_hash = ?, updated_at = NOW()
        WHERE email = ?
    ");
    $stmt->execute([$newHash, $email]);
    $results[$email] = $stmt->rowCount() > 0 ? 'updated' : 'not found';
}

// Verify
$check = $pdo->query("
    SELECT email, is_active, role
    FROM meridian_agency_users
    WHERE email IN ('paul@aivoedge.net', 'tim@aivoedge.net')
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'status'  => 'done',
    'results' => $results,
    'users'   => $check,
    'message' => 'Password reset. DELETE THIS FILE NOW.',
], JSON_PRETTY_PRINT);
