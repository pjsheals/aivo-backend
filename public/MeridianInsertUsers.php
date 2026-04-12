<?php
/**
 * AIVO Meridian — Insert Admin Users
 * Inserts paul@aivoedge.net and tim@aivoedge.net into the existing aivo-edge agency.
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
    $parts = parse_url($dbUrl);
    $dsn   = "pgsql:host={$parts['host']};port={$parts['port']};dbname=" . ltrim($parts['path'], '/') . ";sslmode=require";
    $pdo   = new PDO($dsn, $parts['user'], $parts['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

// Get the aivo-edge agency ID
$agency = $pdo->query("SELECT id FROM meridian_agencies WHERE slug = 'aivo-edge' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$agency) {
    echo json_encode(['error' => 'Agency aivo-edge not found']);
    exit;
}
$agencyId = $agency['id'];

$hash    = password_hash('L3adbank1@64', PASSWORD_BCRYPT, ['cost' => 12]);
$results = [];
$users   = [
    ['paul@aivoedge.net', 'Paul', 'Sheals'],
    ['tim@aivoedge.net',  'Tim',  'de Rosen'],
];

foreach ($users as [$email, $first, $last]) {
    // Check if already exists
    $exists = $pdo->prepare("SELECT id FROM meridian_agency_users WHERE email = ?");
    $exists->execute([$email]);
    if ($exists->fetch()) {
        // Update password
        $pdo->prepare("UPDATE meridian_agency_users SET password_hash = ?, updated_at = NOW() WHERE email = ?")
            ->execute([$hash, $email]);
        $results[$email] = 'password updated';
    } else {
        // Insert
        $pdo->prepare("
            INSERT INTO meridian_agency_users
                (agency_id, email, password_hash, first_name, last_name, role, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'admin', true, NOW(), NOW())
        ")->execute([$agencyId, $email, $hash, $first, $last]);
        $results[$email] = 'created';
    }
}

// Verify
$check = $pdo->query("
    SELECT email, first_name, last_name, role, is_active
    FROM meridian_agency_users
    WHERE email IN ('paul@aivoedge.net', 'tim@aivoedge.net')
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'status'   => 'done',
    'agencyId' => $agencyId,
    'results'  => $results,
    'users'    => $check,
    'message'  => 'Users created. DELETE THIS FILE NOW.',
], JSON_PRETTY_PRINT);
